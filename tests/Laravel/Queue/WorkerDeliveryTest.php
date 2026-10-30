<?php

declare(strict_types=1);

use Illuminate\Bus\Dispatcher as BusDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Config\Repository as ConfigRepository;
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
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Queue\Worker;
use Illuminate\Support\Carbon;
use Ske\Bridge\Laravel\Queue\CloudTasksConnector;
use Ske\Bridge\Laravel\Queue\CloudTasksQueue;
use Ske\Bridge\Laravel\Queue\QueueRunner;
use Ske\Bridge\Queue\JobOutcome;

/*
 * A job's whole round trip with the framework's real Worker: dispatched through
 * the Cloud Tasks queue, handed back as the record the platform builds from
 * the push, and run by QueueRunner. What the outcome says is what the platform
 * acts on: deleted and failed are acknowledged (2xx), released is delivered again
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

/** A job that runs longer than its queue allows. */
final class SlowJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /** @var list<string> */
    public static array $log = [];

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(public bool $failOnTimeout = false, public ?int $timeout = null) {}

    public function handle(): void
    {
        self::$log[] = 'handle';

        sleep(10);

        self::$log[] = 'finished';
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

    /** @var list<JobTimedOut> */
    public array $timedOutEvents = [];

    /** @var list<Throwable> what the application's exception handler was given */
    public array $reported = [];

    /** Thrown by the exception handler after it records a report. */
    public ?Throwable $reportThrows = null;

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
        $events->listen(JobTimedOut::class, function (JobTimedOut $event): void {
            $this->timedOutEvents[] = $event;
        });
        $this->app->instance('events', $events);
        $this->app->instance(EventsContract::class, $events);
        $this->app->instance(BusDispatcherContract::class, new BusDispatcher($this->app));
        $this->app->instance(ExceptionHandler::class, recordingHandler(function (Throwable $e): void {
            $this->reported[] = $e;

            if ($this->reportThrows !== null) {
                throw $this->reportThrows;
            }
        }));
        // Laravel reads the failed job settings when a timed-out job fails.
        $this->app->instance('config', new ConfigRepository(['queue' => ['failed' => []]]));

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
        SlowJob::$log = [];
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
    public function dispatch(DeliveredJob|SlowJob $job): string
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

    /**
     * Runs one delivered record, as the platform hands it over.
     *
     * @param  array{timeout?: int, tries?: int, backoff?: int}  $options
     */
    public function deliver(string $body, int $attempts, bool $requeue = true, array $options = []): JobOutcome
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
        ]], $options)[0];
    }

    /** @return list<array<mixed>> */
    public function failedJobs(): array
    {
        return array_values(array_map(fn (object $row): array => (array) $row, $this->db->getConnection()->table('failed_jobs')->get()->all()));
    }
}

function silentHandler(): ExceptionHandler
{
    return recordingHandler(function (): void {});
}

/**
 * @param  Closure(Throwable): void  $record
 */
function recordingHandler(Closure $record): ExceptionHandler
{
    return new class($record) implements ExceptionHandler
    {
        /**
         * @param  Closure(Throwable): void  $record
         */
        public function __construct(private Closure $record) {}

        public function report(Throwable $e): void
        {
            ($this->record)($e);
        }

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
        ->and(array_map(fn (Throwable $e) => $e->getMessage(), $delivery->reported))->toBe(['the mail server is down'])
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
        // Failed for good: finished, so the platform answers 2xx.
        ->and($outcome->failed)->toBeTrue()
        ->and($outcome->released)->toBeFalse()
        ->and($delivery->tasksCreated())->toHaveCount(1);
});

it('keeps a failed job\'s outcome when the exception handler throws while reporting it', function () {
    $delivery = new Delivery;
    $delivery->reportThrows = new RuntimeException('the log channel is broken');

    $outcome = $delivery->deliver($delivery->dispatch(new DeliveredJob(throws: true)), attempts: 2);

    expect(DeliveredJob::$log)->toBe(['handle', 'failed: the mail server is down'])
        ->and($delivery->reported)->toHaveCount(1)
        ->and($outcome->failed)->toBeTrue()
        ->and($outcome->error)->toBe('the mail server is down')
        ->and($delivery->failedJobs())->toHaveCount(1);
});

it('does not report a job\'s exception when the worker is told not to', function () {
    $delivery = new Delivery;
    Worker::$reportJobExceptions = false;

    try {
        $outcome = $delivery->deliver($delivery->dispatch(new DeliveredJob(throws: true)), attempts: 1);
    } finally {
        Worker::$reportJobExceptions = true;
    }

    expect($outcome->error)->toBe('the mail server is down')
        ->and($delivery->reported)->toBe([]);
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

it('stops a job that runs past the queue timeout and puts it back for another attempt', function () {
    // A queue timeout of 2 seconds leaves the job 1: the function still has
    // time to put the job back and answer before the platform stops it.
    $delivery = new Delivery;
    $body = $delivery->dispatch(new SlowJob);

    $started = microtime(true);
    $outcome = $delivery->deliver($body, attempts: 1, options: ['timeout' => 2]);

    expect(microtime(true) - $started)->toBeLessThan(2)
        ->and(SlowJob::$log)->toBe(['handle'])
        ->and($delivery->timedOutEvents)->toHaveCount(1)
        ->and($outcome->error)->toBe(SlowJob::class.' has timed out.')
        ->and($delivery->reported)->toHaveCount(1)
        ->and($delivery->reported[0])->toBeInstanceOf(TimeoutExceededException::class)
        ->and($delivery->tasksCreated())->toHaveCount(2)
        ->and($outcome->deleted)->toBeTrue()
        ->and($delivery->failedJobs())->toBe([]);
});

it('fails a job that runs out of time on its last attempt', function () {
    $delivery = new Delivery;
    $outcome = $delivery->deliver($delivery->dispatch(new SlowJob), attempts: 2, options: ['timeout' => 2]);

    expect(SlowJob::$log)->toBe(['handle', 'failed: '.SlowJob::class.' has timed out.'])
        ->and($outcome->failed)->toBeTrue()
        ->and($delivery->failedJobs())->toHaveCount(1);
});

it('fails a job on its first timeout when it fails on timeout', function () {
    $delivery = new Delivery;
    $outcome = $delivery->deliver($delivery->dispatch(new SlowJob(failOnTimeout: true)), attempts: 1, options: ['timeout' => 2]);

    expect($outcome->failed)->toBeTrue()
        ->and($delivery->failedJobs())->toHaveCount(1)
        ->and($delivery->tasksCreated())->toHaveCount(1);
});

it('lets a job lower its timeout but not raise it past the queue\'s', function () {
    $delivery = new Delivery;

    $started = microtime(true);
    $delivery->deliver($delivery->dispatch(new SlowJob(timeout: 1)), attempts: 1, options: ['timeout' => 60]);
    $lowered = microtime(true) - $started;

    $started = microtime(true);
    $delivery->deliver($delivery->dispatch(new SlowJob(timeout: 600)), attempts: 1, options: ['timeout' => 2]);
    $capped = microtime(true) - $started;

    expect($lowered)->toBeLessThan(2)
        ->and($capped)->toBeLessThan(2)
        ->and($delivery->timedOutEvents)->toHaveCount(2);
});
