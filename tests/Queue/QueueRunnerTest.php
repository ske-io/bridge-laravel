<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Ske\Bridge\Laravel\Queue\PlatformJob;
use Ske\Bridge\Laravel\Queue\QueueRunner;

/**
 * A stand-in worker that records what it was handed and lets a test decide what
 * the job did. The real Worker needs a queue manager, an event dispatcher and an
 * exception handler; none of that is what this module contributes.
 */
final class RecordingWorker extends Worker
{
    /** @var list<JobContract> */
    public array $handled = [];

    public ?WorkerOptions $lastOptions = null;

    /** @var (Closure(PlatformJob): void)|null */
    public ?Closure $onProcess = null;

    public function __construct() {}

    public function process($connectionName, $job, WorkerOptions $options)
    {
        $this->handled[] = $job;
        $this->lastOptions = $options;

        if ($this->onProcess !== null && $job instanceof PlatformJob) {
            ($this->onProcess)($job);
        }
    }
}

function makeRunner(RecordingWorker $worker): QueueRunner
{
    return new QueueRunner($worker, new Container);
}

it('returns one outcome per record, in order', function () {
    $worker = new RecordingWorker;
    $worker->onProcess = fn (PlatformJob $job) => $job->delete();

    $outcomes = makeRunner($worker)->process([
        ['message_id' => 'a', 'body' => '{"n":1}'],
        ['message_id' => 'b', 'body' => '{"n":2}'],
    ]);

    expect($outcomes)->toHaveCount(2)
        ->and(array_map(fn ($o) => $o->messageId, $outcomes))->toBe(['a', 'b'])
        ->and($outcomes[0]->deleted)->toBeTrue()
        ->and($worker->handled)->toHaveCount(2);
});

it('carries the deployment queue settings into the worker', function () {
    // The adapter forwards these from the environment's queue settings. The
    // previous bridge constructed a bare WorkerOptions, so every one of them
    // reached the worker as a framework default.
    $worker = new RecordingWorker;

    makeRunner($worker)->process(
        [['message_id' => 'a', 'body' => '{}']],
        ['timeout' => 900, 'tries' => 5, 'backoff' => 10],
    );

    // The job timeout stays below the queue's, which is the function's own.
    expect($worker->lastOptions->timeout)->toBe(895)
        ->and($worker->lastOptions->maxTries)->toBe(5)
        ->and($worker->lastOptions->backoff)->toBe(10);
});

it('never lets the worker wait for more work', function () {
    // One invocation handles the messages it was given and returns; sleeping or
    // blocking would burn the function's billed duration doing nothing.
    $worker = new RecordingWorker;

    makeRunner($worker)->process([['message_id' => 'a', 'body' => '{}']]);

    expect($worker->lastOptions->sleep)->toBe(0)
        ->and($worker->lastOptions->stopWhenEmpty)->toBeTrue();
});

it('keeps framework defaults for settings the deployment did not name', function () {
    $worker = new RecordingWorker;
    $defaults = new WorkerOptions;

    makeRunner($worker)->process([['message_id' => 'a', 'body' => '{}']], ['tries' => 3]);

    expect($worker->lastOptions->timeout)->toBe($defaults->timeout)
        ->and($worker->lastOptions->maxTries)->toBe(3)
        ->and($worker->lastOptions->backoff)->toBe($defaults->backoff);
});

it('keeps the job timeout below the queue timeout', function (int $queueTimeout, int $jobTimeout) {
    expect(QueueRunner::jobTimeout($queueTimeout))->toBe($jobTimeout);
})->with([
    'the default queue' => [60, 55],
    'the longest Lambda' => [900, 895],
    'a short queue keeps half' => [6, 3],
    'two seconds' => [2, 1],
    'one second cannot go lower' => [1, 1],
]);

it('hands the worker the message the platform delivered', function () {
    $worker = new RecordingWorker;

    makeRunner($worker)->process([
        ['message_id' => 'msg-9', 'body' => '{"displayName":"SendInvoice"}', 'attempts' => 3],
    ]);

    $job = $worker->handled[0];

    expect($job)->toBeInstanceOf(PlatformJob::class)
        ->and($job->getJobId())->toBe('msg-9')
        ->and($job->getRawBody())->toBe('{"displayName":"SendInvoice"}')
        ->and($job->attempts())->toBe(3);
});

it('tolerates a record missing its fields', function () {
    $worker = new RecordingWorker;

    $outcomes = makeRunner($worker)->process([[]]);

    expect($outcomes)->toHaveCount(1)
        ->and($worker->handled[0]->getRawBody())->toBe('{}')
        ->and($worker->handled[0]->attempts())->toBe(1);
});

it('reports a released job so the adapter makes it visible again', function () {
    $worker = new RecordingWorker;
    $worker->onProcess = fn (PlatformJob $job) => $job->release(45);

    $outcome = makeRunner($worker)->process([['message_id' => 'a', 'body' => '{}']])[0];

    expect($outcome->released)->toBeTrue()
        ->and($outcome->releaseDelay)->toBe(45)
        ->and($outcome->deleted)->toBeFalse();
});

it('names the job\'s queue as the application does, whatever the provider calls it', function (array $runner, string $platformQueue, string $queue) {
    $worker = new RecordingWorker;

    (new QueueRunner($worker, new Container, ...$runner))->process([
        ['message_id' => 'a', 'body' => '{}', 'queue' => $platformQueue],
    ]);

    expect($worker->handled[0]->getQueue())->toBe($queue);
})->with([
    'a Cloud Tasks queue' => [['queuePrefix' => 'ske-shop-prod--'], 'ske-shop-prod--emails', 'emails'],
    'an SQS queue' => [['queueSuffix' => '--ske-shop-prod'], 'emails--ske-shop-prod', 'emails'],
    'no queue named' => [[], '', 'default'],
]);

it('records a job that failed for good with the failed job provider', function () {
    $worker = new RecordingWorker;
    $worker->onProcess = fn (PlatformJob $job) => $job->markAsFailed();
    $failer = new RecordingFailer;

    $outcome = (new QueueRunner($worker, new Container, failer: $failer))->process([
        ['message_id' => 'a', 'body' => '{"uuid":"u-1"}', 'queue' => 'emails'],
    ])[0];

    expect($failer->logged)->toBe([['ske', 'emails', '{"uuid":"u-1"}']])
        ->and($outcome->failed)->toBeTrue()
        ->and($outcome->deadLetter)->toBeFalse();
});

it('hands a job that failed for good back as a dead letter when nothing records it', function (?FailedJobProviderInterface $failer) {
    // Without a database the failed job store is the null driver: an
    // acknowledged message would leave no trace of the job.
    $worker = new RecordingWorker;
    $worker->onProcess = fn (PlatformJob $job) => $job->markAsFailed();

    $outcome = (new QueueRunner($worker, new Container, failer: $failer))->process([
        ['message_id' => 'a', 'body' => '{"uuid":"u-1"}', 'attempts' => 3],
    ])[0];

    expect($outcome->failed)->toBeTrue()
        ->and($outcome->deadLetter)->toBeTrue()
        ->and($outcome->deleted)->toBeFalse()
        ->and($outcome->released)->toBeFalse()
        ->and($outcome->attempts)->toBe(3);
})->with([
    'the null driver' => fn () => new NullFailedJobProvider,
    'no failed job provider' => [null],
]);

it('does not make a dead letter of a job that was released or finished', function (Closure $outcome) {
    $worker = new RecordingWorker;
    $worker->onProcess = $outcome;

    $result = (new QueueRunner($worker, new Container, failer: new NullFailedJobProvider))->process([
        ['message_id' => 'a', 'body' => '{}'],
    ])[0];

    expect($result->deadLetter)->toBeFalse();
})->with([
    'released' => fn () => fn (PlatformJob $job) => $job->release(10),
    'deleted' => fn () => fn (PlatformJob $job) => $job->delete(),
]);

it('keeps a failed job failed when failed_jobs cannot be written, and reports why', function () {
    // Its failed() hook has run; a 500 here would have the platform deliver
    // it again and run the hook again, and on SQS lose the batch's outcomes.
    // Unrecorded, it goes back as a dead letter.
    $worker = new RecordingWorker;
    $worker->onProcess = fn (PlatformJob $job) => $job->markAsFailed();
    $failer = new RecordingFailer;
    $failer->throw = new RuntimeException('no such table: failed_jobs');
    $container = new Container;
    $handler = new RecordingExceptionHandler;
    $container->instance(ExceptionHandler::class, $handler);

    $outcomes = (new QueueRunner($worker, $container, failer: $failer))->process([
        ['message_id' => 'a', 'body' => '{"uuid":"u-1"}'],
        ['message_id' => 'b', 'body' => '{"uuid":"u-2"}'],
    ]);

    expect($outcomes)->toHaveCount(2)
        ->and($outcomes[0]->failed)->toBeTrue()
        ->and($outcomes[0]->deadLetter)->toBeTrue()
        ->and($outcomes[0]->released)->toBeFalse()
        ->and($outcomes[0]->error)->toBe('no such table: failed_jobs')
        ->and($handler->reported)->toBe(['no such table: failed_jobs', 'no such table: failed_jobs']);
});

final class RecordingExceptionHandler implements ExceptionHandler
{
    /** @var list<string> */
    public array $reported = [];

    public function report(Throwable $e): void
    {
        $this->reported[] = $e->getMessage();
    }

    public function shouldReport(Throwable $e): bool
    {
        return true;
    }

    public function render($request, Throwable $e)
    {
        throw $e;
    }

    public function renderForConsole($output, Throwable $e): void {}
}

it('does not record a job that only asked to be tried again', function () {
    $worker = new RecordingWorker;
    $worker->onProcess = fn (PlatformJob $job) => $job->release(10);
    $failer = new RecordingFailer;

    $outcome = (new QueueRunner($worker, new Container, failer: $failer))->process([['message_id' => 'a', 'body' => '{}']])[0];

    expect($failer->logged)->toBe([])
        ->and($outcome->released)->toBeTrue();
});

final class RecordingFailer implements FailedJobProviderInterface
{
    /** @var list<array{0: string, 1: string, 2: string}> */
    public array $logged = [];

    public ?Throwable $throw = null;

    public function log($connection, $queue, $payload, $exception)
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        $this->logged[] = [$connection, $queue, $payload];

        return null;
    }

    /** @return list<mixed> */
    public function ids($queue = null)
    {
        return [];
    }

    /** @return list<mixed> */
    public function all()
    {
        return [];
    }

    public function find($id)
    {
        return null;
    }

    public function forget($id)
    {
        return false;
    }

    public function flush($hours = null) {}
}

it('processes an empty batch without touching the worker', function () {
    $worker = new RecordingWorker;

    expect(makeRunner($worker)->process([]))->toBe([])
        ->and($worker->handled)->toBe([]);
});
