<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Queue;

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\ManuallyFailedException;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Throwable;

/**
 * Runs the messages the platform delivered in one invocation.
 *
 * Options come from the deployment's queue configuration rather than the
 * framework's defaults: the environment's queue settings define timeout and
 * memory, the API validates them and the adapter carries them. Tries and
 * backoff are applied when the adapter sends them; the queue settings don't
 * define them today.
 *
 * A job that fails for good is recorded with the failed job provider, as
 * `queue:work` does. A released job goes back to the platform: on a queue that
 * can schedule (Cloud Tasks) it is put back as a new message due after its
 * delay, carrying its attempts; elsewhere the platform redelivers it.
 */
final readonly class QueueRunner
{
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
     * @param  array{timeout?: int, memory?: int, tries?: int, backoff?: int}  $options
     * @return list<JobOutcome> one per record, in the order they arrived
     */
    public function process(array $records, array $options = []): array
    {
        $workerOptions = $this->workerOptions($options);
        $outcomes = [];

        foreach ($records as $record) {
            $outcomes[] = $this->processOne($record, $workerOptions);
        }

        return $outcomes;
    }

    /**
     * @param  array{message_id?: string, body?: string, queue?: string, attempts?: int}  $record
     */
    private function processOne(array $record, WorkerOptions $options): JobOutcome
    {
        $job = new PlatformJob(
            container: $this->container,
            messageId: (string) ($record['message_id'] ?? ''),
            payload: (string) ($record['body'] ?? '{}'),
            connectionName: $this->connectionName,
            queue: $this->queueName((string) ($record['queue'] ?? '')),
            attempts: (int) ($record['attempts'] ?? 1),
        );

        try {
            $this->worker->process($this->connectionName, $job, $options);
        } catch (Throwable $e) {
            // The worker has already failed or released the job; it rethrows
            // so a daemon can report it. Only the message is left to keep.
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

        return $job->outcome();
    }

    /**
     * Records a job that failed for good in failed_jobs. The job stays failed
     * when that cannot be written (no table, the database briefly away): its
     * failed() hook and the JobFailed event have already run, and delivering it
     * again would run them a second time. The write error is reported instead.
     */
    private function recordFailure(PlatformJob $job): void
    {
        try {
            $this->failer?->log(
                $this->connectionName,
                $job->getQueue(),
                $job->getRawBody(),
                $job->failure() ?? new ManuallyFailedException,
            );
        } catch (Throwable $e) {
            $job->recordError($e);

            if ($this->container->bound(ExceptionHandler::class)) {
                $this->container->make(ExceptionHandler::class)->report($e);
            }
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
     * @param  array{timeout?: int, memory?: int, tries?: int, backoff?: int}  $options
     */
    private function workerOptions(array $options): WorkerOptions
    {
        $workerOptions = new WorkerOptions;

        $workerOptions->timeout = $options['timeout'] ?? $workerOptions->timeout;
        $workerOptions->memory = $options['memory'] ?? $workerOptions->memory;
        $workerOptions->maxTries = $options['tries'] ?? $workerOptions->maxTries;
        $workerOptions->backoff = $options['backoff'] ?? $workerOptions->backoff;

        // The platform invokes once per delivery, so the worker must never
        // block waiting for more work or sleep between messages.
        $workerOptions->sleep = 0;
        $workerOptions->stopWhenEmpty = true;

        return $workerOptions;
    }
}
