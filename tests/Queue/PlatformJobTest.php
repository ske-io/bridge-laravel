<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as EventsContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Ske\Bridge\Laravel\Queue\PlatformJob;

/** A job handler with a failed() hook, which is what Laravel calls on failure. */
final class FailingHandler
{
    /** @var list<string> */
    public static array $failures = [];

    /** @param  array<string, mixed>  $data */
    public function failed(array $data, ?Throwable $e): void
    {
        self::$failures[] = $e?->getMessage() ?? 'manual';
    }
}

const FAILING_BODY = '{"uuid":"u-1","job":"FailingHandler@handle","data":{}}';

function makeJob(string $id = 'msg-1', string $body = '{"job":"Test"}', int $attempts = 1, ?Container $container = null): PlatformJob
{
    return new PlatformJob(
        container: $container ?? new Container,
        messageId: $id,
        payload: $body,
        connectionName: 'ske',
        queue: 'default',
        attempts: $attempts,
    );
}

it('exposes the message the platform delivered', function () {
    $job = makeJob(id: 'abc-123', body: '{"displayName":"SendInvoice"}', attempts: 2);

    expect($job->getJobId())->toBe('abc-123')
        ->and($job->getRawBody())->toBe('{"displayName":"SendInvoice"}')
        ->and($job->attempts())->toBe(2)
        ->and($job->getQueue())->toBe('default')
        ->and($job->getConnectionName())->toBe('ske');
});

it('records a deletion instead of performing one', function () {
    // The receipt handle lives with the adapter, so the application states the
    // decision rather than talking to the broker. The previous bridge invented a
    // handle from the message id; deletion was rejected and every successful job
    // was redelivered until the queue gave up on it.
    $job = makeJob();

    $job->delete();

    $outcome = $job->outcome();

    expect($outcome->messageId)->toBe('msg-1')
        ->and($outcome->deleted)->toBeTrue()
        ->and($outcome->released)->toBeFalse()
        ->and($outcome->failed)->toBeFalse();
});

it('records a release with its delay', function () {
    $job = makeJob();

    $job->release(30);

    $outcome = $job->outcome();

    expect($outcome->released)->toBeTrue()
        ->and($outcome->releaseDelay)->toBe(30)
        ->and($outcome->deleted)->toBeFalse();
});

it('does not report a released job as deleted', function () {
    // Laravel's worker deletes as part of releasing; the adapter must make the
    // message visible again, not remove it.
    $job = makeJob();

    $job->release(0);
    $job->delete();

    expect($job->outcome()->deleted)->toBeFalse()
        ->and($job->outcome()->released)->toBeTrue();
});

it('fails through Laravel\'s failure handling and reports the job finished', function () {
    // The failed() hook and the JobFailed event are what an application hangs
    // its failure handling on; skipping them, as the bridge used to, lost every
    // failure silently.
    FailingHandler::$failures = [];
    $container = new Container;
    $events = new Dispatcher($container);
    $seen = [];
    $events->listen(JobFailed::class, function (JobFailed $event) use (&$seen): void {
        $seen[] = $event->exception->getMessage();
    });
    $container->instance(EventsContract::class, $events);

    $job = makeJob(body: FAILING_BODY, container: $container);
    $job->fail(new RuntimeException('Connection timed out'));

    $outcome = $job->outcome();

    expect(FailingHandler::$failures)->toBe(['Connection timed out'])
        ->and($seen)->toBe(['Connection timed out'])
        ->and($job->failure()?->getMessage())->toBe('Connection timed out')
        ->and($outcome->failed)->toBeTrue()
        // Failed is its own outcome: the adapter acknowledges it, it is
        // neither redelivered nor counted as deleted.
        ->and($outcome->deleted)->toBeFalse()
        ->and($outcome->released)->toBeFalse()
        ->and($outcome->error)->toBe('Connection timed out');
});

it('counts the attempts made before the job was put back as a new message', function () {
    expect(makeJob(body: '{"job":"Test","attempts":2}', attempts: 1)->attempts())->toBe(3)
        ->and(makeJob(body: 'not json', attempts: 2)->attempts())->toBe(2);
});

it('reports a requeued job as done', function () {
    $job = makeJob();

    $job->release(30);
    $job->markRequeued();

    expect($job->outcome()->deleted)->toBeTrue()
        ->and($job->outcome()->released)->toBeFalse();
});

it('records a failure without an error when none is given', function () {
    $job = makeJob();

    $job->markAsFailed();

    expect($job->hasFailed())->toBeTrue()
        ->and($job->outcome()->failed)->toBeTrue()
        ->and($job->outcome()->error)->toBeNull();
});

it('reports an untouched job as neither deleted nor released', function () {
    expect(makeJob()->outcome()->toArray())->toBe([
        'message_id' => 'msg-1',
        'deleted' => false,
        'released' => false,
        'release_delay' => null,
        'failed' => false,
        'dead_letter' => false,
        'attempts' => 1,
        'error' => null,
    ]);
});
