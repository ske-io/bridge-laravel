<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Queue;

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Queue\ManuallyFailedException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Ske\Bridge\Queue\JobOutcome;
use Throwable;

/**
 * Runs the messages the platform delivered in one invocation.
 *
 * Options come from the environment's queue settings rather than the
 * framework's defaults: the platform sends them with each batch. Each is
 * optional, and the worker's own default applies to one that is missing.
 *
 * The timeout sent is the queue's, which is also how long the platform lets
 * the function run. The job timeout is kept TIMEOUT_MARGIN seconds below it
 * (half the queue's timeout when that is shorter), and a job's own `$timeout`
 * can lower it but not raise it past that. A job that runs out of time is
 * stopped with a TimeoutExceededException and then failed or released as
 * `queue:work` would, so its outcome still reaches the platform before the
 * function is stopped. Stopping a job needs the pcntl extension; without it
 * the platform's own timeout is the only limit.
 *
 * A job that fails for good is recorded with the failed job provider, as
 * `queue:work` does. Without one that keeps it (the null driver of an app with
 * no database), it goes back to the platform as a dead letter instead. A
 * released job goes back to the platform: on a queue that can schedule (Cloud
 * Tasks) it is put back as a new message due after its delay, carrying its
 * attempts; elsewhere the platform redelivers it.
 */
final readonly class QueueRunner
{
    /**
     * Seconds kept between the job timeout and the queue's timeout, for
     * failing or releasing a job that ran out of time and answering the
     * platform.
     */
    public const int TIMEOUT_MARGIN = 5;

    public function __construct(
        private Worker $worker,
        private Container $container,
        private ?FailedJobProviderInterface $failer = null,
        private ?CloudTasksQueue $requeue = null,
        private string $connectionName = 'ske',
        private string $queuePrefix = '',
        private string $queueSuffix = '',
    ) {}

    /**
     * @param  list<array{message_id?: string, body?: string, queue?: string, attempts?: int}>  $records
     * @param  array{timeout?: int, tries?: int, backoff?: int}  $options
     * @return list<JobOutcome> one per record, in the order they arrived
     */
    public function process(array $records, array $options = []): array
    {
        $limit = isset($options['timeout']) ? self::jobTimeout($options['timeout']) : null;
        $workerOptions = $this->workerOptions($options, $limit);
        $outcomes = [];

        foreach ($records as $record) {
            $outcomes[] = $this->processOne($record, $workerOptions, $limit);
        }

        return $outcomes;
    }

    /**
     * The longest a job may run on a queue with the given timeout.
     */
    public static function jobTimeout(int $queueTimeout): int
    {
        return max(1, $queueTimeout - min(self::TIMEOUT_MARGIN, intdiv($queueTimeout, 2)));
    }

    /**
     * @param  array{message_id?: string, body?: string, queue?: string, attempts?: int}  $record
     */
    private function processOne(array $record, WorkerOptions $options, ?int $limit): JobOutcome
    {
        $job = new PlatformJob(
            container: $this->container,
            messageId: (string) ($record['message_id'] ?? ''),
            payload: (string) ($record['body'] ?? '{}'),
            connectionName: $this->connectionName,
            queue: $this->queueName((string) ($record['queue'] ?? '')),
            attempts: (int) ($record['attempts'] ?? 1),
        );

        $exception = null;

        try {
            $this->processWithin($job, $options, $this->timeoutFor($job, $options, $limit));
        } catch (Throwable $e) {
            // The worker has already failed or released the job and rethrows
            // for the caller to report. That covers a job stopped by the
            // timeout too.
            $exception = $e;
            $job->recordError($e);

            if (! $job->hasFailed() && ! $job->isReleased() && ! $job->isDeleted()) {
                $job->release();
            }
        }

        if ($job->hasFailed()) {
            $this->recordFailure($job);
        } elseif ($job->isReleased()) {
            $this->requeue($job);
        }

        // Reported once the job's outcome is saved, as `queue:work` does.
        if ($exception !== null && (! property_exists(Worker::class, 'reportJobExceptions') || Worker::$reportJobExceptions)) {
            $this->report($job, $exception);
        }

        return $job->outcome();
    }

    /**
     * The seconds this job may run: its own timeout or the worker's, never
     * past the queue's limit.
     */
    private function timeoutFor(PlatformJob $job, WorkerOptions $options, ?int $limit): int
    {
        $timeout = (int) ($job->timeout() ?? $options->timeout);

        if ($limit !== null && ($timeout <= 0 || $timeout > $limit)) {
            return $limit;
        }

        return $timeout;
    }

    /**
     * Runs the job, stopping it with a TimeoutExceededException once it has
     * run for $timeout seconds. The worker treats that like any other error:
     * it fails the job when no attempt is left and releases it otherwise. A
     * job that sets failOnTimeout is failed first, as `queue:work` does.
     */
    private function processWithin(PlatformJob $job, WorkerOptions $options, int $timeout): void
    {
        if ($timeout <= 0 || ! function_exists('pcntl_alarm')) {
            $this->worker->process($this->connectionName, $job, $options);

            return;
        }

        $async = pcntl_async_signals(true);
        $previous = pcntl_signal_get_handler(SIGALRM);

        pcntl_signal(SIGALRM, function () use ($job, $timeout): void {
            $e = TimeoutExceededException::forJob($job);

            if ($job->shouldFailOnTimeout()) {
                $job->fail($e);
            }

            if ($this->container->bound('events')) {
                $this->container->make('events')->dispatch(new JobTimedOut($this->connectionName, $job, $timeout));
            }

            throw $e;
        });

        pcntl_alarm($timeout);

        try {
            $this->worker->process($this->connectionName, $job, $options);
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previous);
            pcntl_async_signals($async);
        }
    }

    /**
     * Records a job that failed for good in failed_jobs. The job stays failed
     * when there is nowhere to record it, or that cannot be written (no table,
     * the database briefly away): its failed() hook and the JobFailed event
     * have already run, and delivering it again would run them a second time.
     * It is marked a dead letter instead, for the platform to keep, and a write
     * error is reported.
     */
    private function recordFailure(PlatformJob $job): void
    {
        if ($this->failer === null || $this->failer instanceof NullFailedJobProvider) {
            $job->markAsDeadLetter();

            return;
        }

        try {
            $this->failer->log(
                $this->connectionName,
                $job->getQueue(),
                $job->getRawBody(),
                $job->failure() ?? new ManuallyFailedException,
            );
        } catch (Throwable $e) {
            $job->markAsDeadLetter();
            $job->recordError($e);
            $this->report($job, $e);
        }
    }

    /**
     * Hands an error to the application's exception handler, so its
     * dontReport list and reportable callbacks decide what is logged. A
     * handler that throws is kept as the job's error instead: the outcome
     * must still reach the platform.
     */
    private function report(PlatformJob $job, Throwable $e): void
    {
        try {
            if ($this->container->bound(ExceptionHandler::class)) {
                $this->container->make(ExceptionHandler::class)->report($e);
            }
        } catch (Throwable $reportError) {
            $job->recordError($reportError);
        }
    }

    /**
     * Puts a released job back as a new message due after its delay. When that
     * fails the job stays released, and the platform delivers it again.
     */
    private function requeue(PlatformJob $job): void
    {
        if ($this->requeue === null) {
            return;
        }

        $payload = json_decode($job->getRawBody(), true);
        if (! is_array($payload)) {
            return;
        }

        $payload['attempts'] = $job->attempts();

        try {
            $this->requeue->pushRaw(
                (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
                $job->getQueue(),
                ['delay' => $job->releaseDelay() ?? 0],
            );
        } catch (Throwable $e) {
            $job->recordError($e);

            return;
        }

        $job->markRequeued();
    }

    /**
     * The application's name for the queue the platform named: the SQS queue
     * `{queue}{suffix}` or the Cloud Tasks queue `{prefix}{queue}`.
     */
    private function queueName(string $platformQueue): string
    {
        $name = $platformQueue;

        if ($this->queuePrefix !== '' && str_starts_with($name, $this->queuePrefix)) {
            $name = substr($name, strlen($this->queuePrefix));
        }

        if ($this->queueSuffix !== '' && str_ends_with($name, $this->queueSuffix)) {
            $name = substr($name, 0, -strlen($this->queueSuffix));
        }

        return $name !== '' ? $name : 'default';
    }

    /**
     * @param  array{timeout?: int, tries?: int, backoff?: int}  $options
     */
    private function workerOptions(array $options, ?int $limit): WorkerOptions
    {
        $workerOptions = new WorkerOptions;

        $workerOptions->timeout = $limit ?? $workerOptions->timeout;
        $workerOptions->maxTries = $options['tries'] ?? $workerOptions->maxTries;
        $workerOptions->backoff = $options['backoff'] ?? $workerOptions->backoff;

        // The platform invokes once per delivery, so the worker must never
        // block waiting for more work or sleep between messages.
        $workerOptions->sleep = 0;
        $workerOptions->stopWhenEmpty = true;

        return $workerOptions;
    }
}
