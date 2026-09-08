<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Ske\Bridge\Laravel\Queue\PlatformJob;

function makeJob(string $id = 'msg-1', string $body = '{"job":"Test"}', int $attempts = 1): PlatformJob
{
    return new PlatformJob(
        container: new Container,
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

it('records a failure', function () {
    // markAsFailed() rather than fail(): the latter walks the framework's whole
    // failure path — event dispatcher, config, the job's own failed() hook —
    // which is Laravel's behaviour and needs a booted application. What this
    // module adds is the recording, and that is what outcome() reads.
    $job = makeJob();

    $job->markAsFailed();

    expect($job->hasFailed())->toBeTrue()
        ->and($job->outcome()->failed)->toBeTrue();
});

it('reports an untouched job as neither deleted nor released', function () {
    expect(makeJob()->outcome()->toArray())->toBe([
        'message_id' => 'msg-1',
        'deleted' => false,
        'released' => false,
        'release_delay' => null,
        'failed' => false,
    ]);
});
