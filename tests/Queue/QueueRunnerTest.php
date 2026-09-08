<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
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

    /** @var (callable(PlatformJob): void)|null */
    public $onProcess = null;

    public function __construct() {}

    public function process($connectionName, $job, WorkerOptions $options)
    {
        $this->handled[] = $job;
        $this->lastOptions = $options;

        if ($this->onProcess !== null) {
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
    // ske.yml defines these, the API validates them and the adapter forwards
    // them. The previous bridge constructed a bare WorkerOptions, so every one
    // of them reached the worker as a framework default.
    $worker = new RecordingWorker;

    makeRunner($worker)->process(
        [['message_id' => 'a', 'body' => '{}']],
        ['timeout' => 120, 'memory' => 512, 'tries' => 5, 'backoff' => 10],
    );

    expect($worker->lastOptions->timeout)->toBe(120)
        ->and($worker->lastOptions->memory)->toBe(512)
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

    makeRunner($worker)->process([['message_id' => 'a', 'body' => '{}']], ['timeout' => 99]);

    expect($worker->lastOptions->timeout)->toBe(99)
        ->and($worker->lastOptions->maxTries)->toBe($defaults->maxTries)
        ->and($worker->lastOptions->memory)->toBe($defaults->memory);
});

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

it('processes an empty batch without touching the worker', function () {
    $worker = new RecordingWorker;

    expect(makeRunner($worker)->process([]))->toBe([])
        ->and($worker->handled)->toBe([]);
});
