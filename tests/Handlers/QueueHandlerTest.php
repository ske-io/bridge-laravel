<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as EventsContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Ske\Bridge\Laravel\Handlers\QueueHandler;
use Ske\Bridge\Laravel\Queue\PlatformJob;
use Ske\Bridge\Laravel\Queue\QueueRunner;

final class QueueHandlerWorker extends Worker
{
    /** @var (Closure(PlatformJob): void)|null */
    public ?Closure $onProcess = null;

    public function __construct() {}

    public function process($connectionName, $job, WorkerOptions $options): void
    {
        if ($this->onProcess !== null && $job instanceof PlatformJob) {
            ($this->onProcess)($job);
        }
    }
}

/** A job handler without a failed() hook. */
final class QueueHandlerNoHook {}

/** @param array<string, mixed> $payload */
function queueRequest(array $payload): Request
{
    return Request::create('/__ske/queue', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], (string) json_encode($payload));
}

it('passes records and options to the runner and returns outcomes', function () {
    $worker = new QueueHandlerWorker;
    $worker->onProcess = fn (PlatformJob $job) => $job->delete();

    $handler = new QueueHandler(new QueueRunner($worker, new Container));

    $response = $handler(queueRequest([
        'records' => [['message_id' => 'msg-1', 'body' => '{"job":"Test"}', 'attempts' => 2]],
        'options' => ['timeout' => 60],
    ]));

    $data = $response->getData(true);

    expect($data['processed'])->toBe(1)
        ->and($data['outcomes'])->toHaveCount(1)
        ->and($data['outcomes'][0]['message_id'])->toBe('msg-1')
        ->and($data['outcomes'][0]['deleted'])->toBeTrue()
        ->and($data['outcomes'][0]['failed'])->toBeFalse();
});

it('defaults to empty records and options when missing', function () {
    $handler = new QueueHandler(new QueueRunner(new QueueHandlerWorker, new Container));

    $response = $handler(queueRequest([]));

    expect($response->getData(true))->toBe(['processed' => 0, 'outcomes' => []]);
});

it('ignores non-array records', function () {
    $handler = new QueueHandler(new QueueRunner(new QueueHandlerWorker, new Container));

    $response = $handler(queueRequest(['records' => 'not-an-array']));

    expect($response->getData(true)['processed'])->toBe(0);
});

it('ignores non-array options', function () {
    $worker = new QueueHandlerWorker;
    $worker->onProcess = fn (PlatformJob $job) => $job->delete();

    $handler = new QueueHandler(new QueueRunner($worker, new Container));

    $response = $handler(queueRequest([
        'records' => [['message_id' => 'a', 'body' => '{}']],
        'options' => 'not-an-array',
    ]));

    expect($response->getData(true)['processed'])->toBe(1);
});

it('includes error details for failed jobs', function () {
    $worker = new QueueHandlerWorker;
    $worker->onProcess = fn (PlatformJob $job) => $job->fail(new RuntimeException('Connection refused'));

    $container = new Container;
    $container->instance(EventsContract::class, new Dispatcher($container));
    $handler = new QueueHandler(new QueueRunner($worker, $container));

    $response = $handler(queueRequest([
        'records' => [['message_id' => 'msg-1', 'body' => '{"uuid":"u-1","job":"QueueHandlerNoHook@handle","data":{}}']],
    ]));

    $outcome = $response->getData(true)['outcomes'][0];

    expect($outcome['failed'])->toBeTrue()
        ->and($outcome['error'])->toBe('Connection refused');
});
