<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Ske\Bridge\Laravel\Queue\CloudTasksConnector;
use Ske\Bridge\Laravel\Queue\CloudTasksQueue;

const TASKS_URL = 'https://cloudtasks.googleapis.com/v2/projects/acme-prod/locations/europe-west1/queues/';
const METADATA_TOKEN_URL = 'http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token';

/**
 * An HTTP client that answers like the metadata server and the Cloud Tasks
 * API, recording what was sent.
 */
function fakeGoogle(int $status = 200): HttpFactory
{
    $http = new HttpFactory;
    $http->fake([
        METADATA_TOKEN_URL => $http->response(['access_token' => 'ya29.token', 'expires_in' => 3599, 'token_type' => 'Bearer']),
        TASKS_URL.'*/tasks' => $status === 200
            ? $http->response(['name' => 'projects/acme-prod/locations/europe-west1/queues/ske-shop-prod--default/tasks/7781203'])
            : $http->response(['error' => ['code' => $status, 'status' => 'PERMISSION_DENIED']], $status),
        TASKS_URL.'*' => $http->response(['name' => 'q', 'stats' => ['tasksCount' => '12', 'concurrentDispatchesCount' => '2']]),
    ]);

    return $http;
}

function cloudTasksQueue(HttpFactory $http): CloudTasksQueue
{
    $queue = (new CloudTasksConnector($http))->connect([
        'project' => 'acme-prod',
        'location' => 'europe-west1',
        'prefix' => 'ske-shop-prod--',
    ]);
    $queue->setContainer(new Container);
    $queue->setConnectionName('ske');

    return $queue;
}

/** @return list<Request> */
function createTaskRequests(HttpFactory $http): array
{
    return array_values($http->recorded(fn (Request $request): bool => str_ends_with($request->url(), '/tasks'))
        ->map(fn (array $pair): Request => $pair[0])
        ->all());
}

final class CloudTasksTestJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public static int $runs = 0;

    public int $tries = 3;

    public function __construct(public bool $throws = false) {}

    public function handle(): void
    {
        self::$runs++;

        if ($this->throws) {
            throw new RuntimeException('the mail server is down');
        }
    }
}

afterEach(fn () => Carbon::setTestNow());

it('creates a task on the queue named for the job, carrying its payload', function () {
    $http = fakeGoogle();

    $id = cloudTasksQueue($http)->push(new CloudTasksTestJob);

    $requests = createTaskRequests($http);
    expect($requests)->toHaveCount(1);

    $request = $requests[0];
    $task = $request->data()['task'];
    $payload = json_decode(base64_decode($task['httpRequest']['body']), true);

    expect($id)->toBe('7781203')
        ->and($request->method())->toBe('POST')
        ->and($request->url())->toBe(TASKS_URL.'ske-shop-prod--default/tasks')
        ->and($request->header('Authorization'))->toBe(['Bearer ya29.token'])
        ->and($task['httpRequest']['httpMethod'])->toBe('POST')
        ->and($task['httpRequest']['url'])->toBe(CloudTasksQueue::TASK_URL)
        ->and($task['httpRequest']['headers'])->toBe(['Content-Type' => 'application/json'])
        ->and($task['dispatchDeadline'])->toBe('1800s')
        ->and($task)->not->toHaveKey('scheduleTime')
        ->and($payload['displayName'])->toBe(CloudTasksTestJob::class)
        ->and($payload['job'])->toBe('Illuminate\Queue\CallQueuedHandler@call');
});

it('sends a job for a dedicated queue to that queue', function () {
    $http = fakeGoogle();

    cloudTasksQueue($http)->push(new CloudTasksTestJob, '', 'emails');

    expect(createTaskRequests($http)[0]->url())->toBe(TASKS_URL.'ske-shop-prod--emails/tasks');
});

it('pushes a raw payload unchanged', function () {
    $http = fakeGoogle();

    cloudTasksQueue($http)->pushRaw('{"job":"raw"}', 'reports');

    $request = createTaskRequests($http)[0];

    expect($request->url())->toBe(TASKS_URL.'ske-shop-prod--reports/tasks')
        ->and(base64_decode($request->data()['task']['httpRequest']['body']))->toBe('{"job":"raw"}');
});

it('schedules a delayed job for when it is due', function () {
    Carbon::setTestNow('2026-10-03 12:00:00');
    $http = fakeGoogle();

    cloudTasksQueue($http)->later(90, new CloudTasksTestJob);

    expect(createTaskRequests($http)[0]->data()['task']['scheduleTime'])->toBe('2026-10-03T12:01:30Z');
});

it('accepts a delay of exactly thirty days', function () {
    Carbon::setTestNow('2026-10-03 12:00:00');
    $http = fakeGoogle();

    cloudTasksQueue($http)->later(Carbon::now()->addDays(30), new CloudTasksTestJob);

    expect(createTaskRequests($http)[0]->data()['task']['scheduleTime'])->toBe('2026-11-02T12:00:00Z');
});

it('refuses a delay Cloud Tasks cannot schedule instead of running the job early', function () {
    $http = fakeGoogle();

    expect(fn () => cloudTasksQueue($http)->later(CloudTasksQueue::MAX_DELAY + 1, new CloudTasksTestJob))
        ->toThrow(InvalidArgumentException::class, '30 days');

    expect(createTaskRequests($http))->toBe([]);
});

it('asks the metadata server for a token once, not per job', function () {
    $http = fakeGoogle();
    $queue = cloudTasksQueue($http);

    $queue->push(new CloudTasksTestJob);
    $queue->push(new CloudTasksTestJob);

    $tokenRequests = $http->recorded(fn (Request $request): bool => $request->url() === METADATA_TOKEN_URL);

    expect($tokenRequests)->toHaveCount(1)
        ->and($tokenRequests->first()[0]->header('Metadata-Flavor'))->toBe(['Google']);
});

it('fails the dispatch when Cloud Tasks refuses the task', function () {
    $http = fakeGoogle(403);

    expect(fn () => cloudTasksQueue($http)->push(new CloudTasksTestJob))
        ->toThrow(RequestException::class);
});

it('reads its size from the queue statistics', function () {
    $queue = cloudTasksQueue(fakeGoogle());

    expect($queue->size())->toBe(12)
        ->and($queue->reservedSize())->toBe(2)
        ->and($queue->pendingSize())->toBe(10)
        ->and($queue->pop())->toBeNull();
});

it('tries a throttled or failing API call again before giving up', function (int $status) {
    $http = new HttpFactory;
    $http->fake([
        METADATA_TOKEN_URL => $http->response(['access_token' => 'ya29.token', 'expires_in' => 3599]),
        TASKS_URL.'*' => $http->sequence()
            ->push(['error' => ['code' => $status]], $status)
            ->push(['name' => 'projects/p/locations/l/queues/q/tasks/42']),
    ]);

    expect(cloudTasksQueue($http)->push(new CloudTasksTestJob))->toBe('42')
        ->and(createTaskRequests($http))->toHaveCount(2);
})->with(['throttled' => 429, 'unavailable' => 503]);

it('does not retry a refusal', function () {
    $http = fakeGoogle(403);

    expect(fn () => cloudTasksQueue($http)->push(new CloudTasksTestJob))->toThrow(RequestException::class)
        ->and(createTaskRequests($http))->toHaveCount(1);
});

it('gives up after three tries', function () {
    $http = fakeGoogle(503);

    expect(fn () => cloudTasksQueue($http)->push(new CloudTasksTestJob))->toThrow(RequestException::class)
        ->and(createTaskRequests($http))->toHaveCount(3);
});

it('names each queue by the environment prefix', function () {
    $queue = cloudTasksQueue(fakeGoogle());

    expect($queue->queuePath())->toBe('projects/acme-prod/locations/europe-west1/queues/ske-shop-prod--default')
        ->and($queue->queuePath('emails'))->toBe('projects/acme-prod/locations/europe-west1/queues/ske-shop-prod--emails');
});
