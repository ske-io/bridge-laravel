<?php

declare(strict_types=1);

use Illuminate\Bus\Dispatcher as BusDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher as EventsContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Support\Carbon;
use Ske\Bridge\Laravel\Queue\CloudTasksConnector;
use Ske\Bridge\Laravel\Queue\CloudTasksQueue;
use Ske\Bridge\Laravel\Queue\JobOutcome;
use Ske\Bridge\Laravel\Queue\QueueRunner;

/*
 * A job's whole round trip with the framework's real Worker: dispatched through
 * the Cloud Tasks queue, handed back as the record the adapter builds from the
 * push, and run by QueueRunner. What the outcome says is what the adapter acts
 * on: deleted and failed are acknowledged (2xx), released is delivered again
 * (non-2xx on Cloud Tasks, a batch item failure on SQS).
 */

final class DeliveredJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /** @var list<string> */
    public static array $log = [];

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(public bool $throws = false) {}

    public function handle(): void
    {
        self::$log[] = 'handle';

        if ($this->throws) {
            throw new RuntimeException('the mail server is down');
        }
    }

    public function failed(Throwable $e): void
    {
        self::$log[] = 'failed: '.$e->getMessage();
    }
}

/**
 * The application around one delivery: the real Worker, an events dispatcher
 * that records JobFailed, a failed_jobs table, and the Cloud Tasks queue on a
 * faked Google.
 */
final class Delivery
{
    public Application $app;

    public HttpFactory $http;

    public Capsule $db;

    /** @var list<JobFailed> */
    public array $failedEvents = [];

    /** @var list<self> */
    public static array $open = [];

    private Container $previous;

    public function __construct(int $tasksStatus = 200)
    {
        $this->previous = Container::getInstance();
        $this->app = new Application(sys_get_temp_dir());

        $events = new Dispatcher($this->app);
        $events->listen(JobFailed::class, function (JobFailed $event): void {
            $this->failedEvents[] = $event;
        });
        $this->app->instance('events', $events);
        $this->app->instance(EventsContract::class, $events);
        $this->app->instance(BusDispatcherContract::class, new BusDispatcher($this->app));

        $this->db = new Capsule;
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->getConnection()->getSchemaBuilder()->create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        $this->http = new HttpFactory;
        $this->http->fake([
            'http://metadata.google.internal/*' => $this->http->response(['access_token' => 'ya29.token', 'expires_in' => 3599]),
            'https://cloudtasks.googleapis.com/*' => $tasksStatus === 200
                ? $this->http->response(['name' => 'projects/acme-prod/locations/europe-west1/queues/q/tasks/2'])
                : $this->http->response(['error' => ['code' => $tasksStatus]], $tasksStatus),
        ]);

        DeliveredJob::$log = [];
        self::$open[] = $this;
    }

    public function restore(): void
    {
        Container::setInstance($this->previous);
    }

    public function queue(): CloudTasksQueue
    {
        $queue = (new CloudTasksConnector($this->http))->connect([
            'project' => 'acme-prod',
            'location' => 'europe-west1',
            'prefix' => 'ske-shop-prod--',
        ]);
        $queue->setContainer($this->app);
        $queue->setConnectionName('ske');

        return $queue;
    }

    /** The body of a job as Cloud Tasks would push it. */
    public function dispatch(DeliveredJob $job): string
    {
        $this->queue()->push($job, '', 'emails');

        return $this->lastTaskBody();
    }

    public function lastTaskBody(): string
    {
        $task = $this->tasksCreated()[count($this->tasksCreated()) - 1]->data()['task'];

        return (string) base64_decode($task['httpRequest']['body']);
    }

    /** @return list<Request> */
    public function tasksCreated(): array
    {
        return array_values($this->http->recorded(
            fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/tasks'),
        )->map(fn (array $pair): Request => $pair[0])->all());
    }

    /** Runs one delivered record, as the adapter hands it over. */
    public function deliver(string $body, int $attempts, bool $requeue = true): JobOutcome
    {
        $worker = new Worker(new QueueManager($this->app), $this->app->make('events'), silentHandler(), fn (): bool => false);

        $runner = new QueueRunner(
            worker: $worker,
            container: $this->app,
            failer: new DatabaseUuidFailedJobProvider($this->db->getDatabaseManager(), 'default', 'failed_jobs'),
            requeue: $requeue ? $this->queue() : null,
            queuePrefix: 'ske-shop-prod--',
        );

        return $runner->process([[
            'message_id' => 'task-1',
            'body' => $body,
            'queue' => 'ske-shop-prod--emails',
            'attempts' => $attempts,
        ]])[0];
    }

    /** @return list<array<mixed>> */
    public function failedJobs(): array
    {
        return array_values(array_map(fn (object $row): array => (array) $row, $this->db->getConnection()->table('failed_jobs')->get()->all()));
    }
}

function silentHandler(): ExceptionHandler
{
    return new class implements ExceptionHandler
    {
        public function report(Throwable $e): void {}

        public function shouldReport(Throwable $e): bool
        {
            return false;
        }

        public function render($request, Throwable $e)
        {
            throw $e;
        }

        public function renderForConsole($output, Throwable $e): void {}
    };
}

beforeEach(fn () => Carbon::setTestNow('2026-10-03 12:00:00'));

afterEach(function () {
    foreach (array_reverse(Delivery::$open) as $delivery) {
        $delivery->restore();
    }
    Delivery::$open = [];
    Carbon::setTestNow();
});

it('acknowledges a job that ran', function () {
    $delivery = new Delivery;
    $outcome = $delivery->deliver($delivery->dispatch(new DeliveredJob), attempts: 1);

    expect(DeliveredJob::$log)->toBe(['handle'])
        ->and($outcome->deleted)->toBeTrue()
        ->and($outcome->released || $outcome->failed)->toBeFalse()
        ->and($delivery->failedJobs())->toBe([]);
});

it('puts a released job back on its Cloud Tasks queue after its backoff, and acknowledges the delivery', function () {
    $delivery = new Delivery;
    $body = $delivery->dispatch(new DeliveredJob(throws: true));

    $outcome = $delivery->deliver($body, attempts: 1);

    $tasks = $delivery->tasksCreated();
    $requeued = $tasks[1]->data()['task'];
    $payload = json_decode((string) base64_decode($requeued['httpRequest']['body']), true);

    expect(DeliveredJob::$log)->toBe(['handle'])
        ->and($tasks)->toHaveCount(2)
        ->and($tasks[1]->url())->toEndWith('/queues/ske-shop-prod--emails/tasks')
        ->and($requeued['scheduleTime'])->toBe('2026-10-03T12:00:30Z')
        ->and($payload['attempts'])->toBe(1)
        ->and($payload['uuid'])->toBe(json_decode($body, true)['uuid'])
        ->and($outcome->deleted)->toBeTrue()
        ->and($outcome->released)->toBeFalse()
        ->and($outcome->error)->toBe('the mail server is down')
        ->and($delivery->failedJobs())->toBe([]);
});

it('counts the attempts made before a job was put back, and fails it when they run out', function () {
    $delivery = new Delivery;
    $delivery->deliver($delivery->dispatch(new DeliveredJob(throws: true)), attempts: 1);
    $requeuedBody = $delivery->lastTaskBody();

    // The new task's first delivery is the job's second attempt, its last.
    $outcome = $delivery->deliver($requeuedBody, attempts: 1);

    expect(DeliveredJob::$log)->toBe(['handle', 'handle', 'failed: the mail server is down'])
        ->and($outcome->failed)->toBeTrue()
        ->and($delivery->failedJobs())->toHaveCount(1);
});

it('runs Laravel\'s failure handling when a job fails for good, and acknowledges it', function () {
    $delivery = new Delivery;
    $body = $delivery->dispatch(new DeliveredJob(throws: true));

    $outcome = $delivery->deliver($body, attempts: 2);

    $failed = $delivery->failedJobs();

    expect(DeliveredJob::$log)->toBe(['handle', 'failed: the mail server is down'])
        ->and($delivery->failedEvents)->toHaveCount(1)
        ->and($delivery->failedEvents[0]->connectionName)->toBe('ske')
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]['connection'])->toBe('ske')
        ->and($failed[0]['queue'])->toBe('emails')
        ->and($failed[0]['uuid'])->toBe(json_decode($body, true)['uuid'])
        ->and($failed[0]['exception'])->toContain('the mail server is down')
        // Failed for good: finished, so the adapter answers 2xx.
        ->and($outcome->failed)->toBeTrue()
        ->and($outcome->released)->toBeFalse()
        ->and($delivery->tasksCreated())->toHaveCount(1);
});

it('fails a job delivered more times than it may try, without running it', function () {
    $delivery = new Delivery;
    // A crash or timeout the platform retried past the job's tries.
    $outcome = $delivery->deliver($delivery->dispatch(new DeliveredJob), attempts: 3);

    expect(DeliveredJob::$log)->toHaveCount(1)
        ->and(DeliveredJob::$log[0])->toStartWith('failed: ')
        ->and($delivery->failedJobs())->toHaveCount(1)
        ->and($outcome->failed)->toBeTrue();
});

it('leaves a released job to the platform when there is nothing to put it back on', function () {
    $delivery = new Delivery;
    $outcome = $delivery->deliver($delivery->dispatch(new DeliveredJob(throws: true)), attempts: 1, requeue: false);

    expect($outcome->released)->toBeTrue()
        ->and($outcome->releaseDelay)->toBe(30)
        ->and($outcome->deleted || $outcome->failed)->toBeFalse();
});

it('leaves a released job to Cloud Tasks\' own retry when putting it back fails', function () {
    $delivery = new Delivery;
    $body = $delivery->dispatch(new DeliveredJob(throws: true));

    $outcome = (new Delivery(tasksStatus: 403))->deliver($body, attempts: 1);

    expect($outcome->released)->toBeTrue()
        ->and($outcome->deleted)->toBeFalse();
});
