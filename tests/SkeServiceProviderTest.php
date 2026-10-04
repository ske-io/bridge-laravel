<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Ske\Bridge\Laravel\Http\Middleware\CorrelateRequests;
use Ske\Bridge\Laravel\Http\Middleware\EnsureSkeInternal;
use Ske\Bridge\Laravel\Http\TrustPlatformProxy;
use Ske\Bridge\Laravel\Queue\CloudTasksConnector;
use Ske\Bridge\Laravel\Queue\CloudTasksQueue;
use Ske\Bridge\Laravel\Queue\QueueRunner;
use Ske\Bridge\Laravel\Queue\SqsConnector;
use Ske\Bridge\Laravel\Runtime\PlatformMaintenanceMode;
use Ske\Bridge\Laravel\SkeServiceProvider;

/**
 * The two variables the deploy engine sets on every function it creates. Both
 * are cleared from all three sources Environment reads, so an off-platform test
 * is off-platform whatever the machine running the suite happens to export.
 */
const PLATFORM_VARIABLES = ['SKE_PROJECT_TYPE', 'SKE_ENVIRONMENT'];

/**
 * Runs a callback with the platform either announced or absent, restoring the
 * real environment — and the container and facade globals the application
 * constructor claims — afterwards.
 */
function withPlatform(bool $present, Closure $callback): void
{
    $server = $_SERVER;
    $env = $_ENV;
    $process = array_map(
        fn (string $key): string|false => getenv($key),
        PLATFORM_VARIABLES,
    );
    $container = Container::getInstance();

    foreach (PLATFORM_VARIABLES as $key) {
        unset($_SERVER[$key], $_ENV[$key]);
        putenv($key);
    }

    if ($present) {
        $_SERVER['SKE_ENVIRONMENT'] = 'develop';
    }

    try {
        $callback();
    } finally {
        $_SERVER = $server;
        $_ENV = $env;
        foreach (PLATFORM_VARIABLES as $i => $key) {
            is_string($process[$i]) ? putenv("{$key}={$process[$i]}") : putenv($key);
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance($container);
    }
}

/**
 * The smallest application the provider can be pointed at: a config repository,
 * a router and the filesystem it checks for a route cache, which is all it
 * touches. Deliberately not a booted framework —
 * what is under test is the wiring, not Laravel.
 *
 * @param  array<string, mixed>  $config
 */
function applicationFor(array $config = []): Application
{
    $app = new Application(sys_get_temp_dir());
    $app->instance('config', new Repository($config));
    $app->instance('files', new Filesystem);
    $app->instance('router', new Router(new Dispatcher($app), $app));

    Facade::setFacadeApplication($app);
    Facade::clearResolvedInstances();

    return $app;
}

function registerProvider(Application $app): SkeServiceProvider
{
    $provider = new SkeServiceProvider($app);
    $provider->register();

    return $provider;
}

/** @return list<Route> */
function registeredRoutes(Application $app): array
{
    return array_values($app->make('router')->getRoutes()->getRoutes());
}

// --- off the platform ----------------------------------------------------

it('is inert when the application is not running on the platform', function () {
    // The safety argument for requiring this package unconditionally: a
    // developer running the same application locally gets none of it.
    withPlatform(present: false, callback: function () {
        $app = applicationFor(['logging' => ['default' => 'stack'], 'queue' => ['connections' => ['sqs' => ['key' => 'AKIA', 'secret' => 'shh']]]]);

        registerProvider($app)->boot();

        // The upload endpoint is the one route registered anyway: the
        // application's own frontend calls it wherever the application runs.
        $uris = array_map(fn ($route) => $route->uri(), registeredRoutes($app));

        expect($app->bound(QueueRunner::class))->toBeFalse()
            ->and($uris)->toBe(['ske/signed-storage-url'])
            ->and($app->make('config')->get('logging.default'))->toBe('stack')
            ->and($app->make('config')->get('queue.connections.sqs.key'))->toBe('AKIA');
    });
});

it('recognises either variable the deploy engine sets', function (string $variable) {
    withPlatform(present: false, callback: function () use ($variable) {
        expect(SkeServiceProvider::runningOnPlatform())->toBeFalse();

        $_SERVER[$variable] = 'set';

        expect(SkeServiceProvider::runningOnPlatform())->toBeTrue();
    });
})->with(PLATFORM_VARIABLES);

// --- the upload path -----------------------------------------------------

it('takes the upload path from SKE_UPLOADS_URL, or the default', function (?string $value, string $uri) {
    withPlatform(present: false, callback: function () use ($value, $uri) {
        withSkeEnvVariable('SKE_UPLOADS_URL', function () use ($value, $uri) {
            if ($value !== null) {
                $_SERVER['SKE_UPLOADS_URL'] = $value;
            }

            $app = applicationFor();

            registerProvider($app)->boot();

            expect($app->make('config')->get('ske.uploads.url'))->toBe($value ?? '/ske/signed-storage-url')
                ->and(array_map(fn ($route) => $route->uri(), registeredRoutes($app)))->toBe([$uri]);
        });
    });
})->with([
    'unset' => [null, 'ske/signed-storage-url'],
    'set' => ['/uploads/sign', 'uploads/sign'],
]);

it('lets a published config win over SKE_UPLOADS_URL', function () {
    withPlatform(present: false, callback: function () {
        withSkeEnvVariable('SKE_UPLOADS_URL', function () {
            $_SERVER['SKE_UPLOADS_URL'] = '/uploads/sign';

            $app = applicationFor(['ske' => ['uploads' => [
                'enabled' => true,
                'url' => '/published/sign',
                'middleware' => [],
            ]]]);

            registerProvider($app)->boot();

            expect(array_map(fn ($route) => $route->uri(), registeredRoutes($app)))->toBe(['published/sign']);
        });
    });
});

// --- on the platform -----------------------------------------------------

it('binds the queue runner as a singleton', function () {
    withPlatform(present: true, callback: function () {
        $app = applicationFor();

        registerProvider($app);

        expect($app->isShared(QueueRunner::class))->toBeTrue();
    });
});

it('registers the internal route the adapter calls', function () {
    withPlatform(present: true, callback: function () {
        $app = applicationFor();

        registerProvider($app)->boot();

        $routes = collect(registeredRoutes($app))
            ->mapWithKeys(fn ($route) => [$route->uri() => [$route->methods(), $route->middleware()]]);

        expect($routes->keys()->all())->toBe(['ske/signed-storage-url', '__ske/queue'])
            ->and($routes['__ske/queue'][0])->toContain('POST');
    });
});

it('drives maintenance mode from the platform instead of a file', function () {
    withPlatform(present: true, callback: function () {
        $app = applicationFor();

        registerProvider($app);

        expect($app->make(MaintenanceMode::class))->toBeInstanceOf(PlatformMaintenanceMode::class)
            ->and($app->isShared(MaintenanceMode::class))->toBeTrue();
    });
});

it('keeps the adapter routes and the readiness probe open during maintenance', function () {
    withPlatform(present: true, callback: function () {
        PreventRequestsDuringMaintenance::flushState();
        $app = applicationFor();

        registerProvider($app)->boot();

        expect((new PreventRequestsDuringMaintenance($app))->getExcludedPaths())
            ->toContain('__ske/*', 'up');

        PreventRequestsDuringMaintenance::flushState();
    });
});

it('guards the internal route', function () {
    withPlatform(present: true, callback: function () {
        $app = applicationFor();

        registerProvider($app)->boot();

        $guarded = collect(registeredRoutes($app))
            ->filter(fn ($route) => in_array(EnsureSkeInternal::class, $route->middleware(), true))
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all();

        expect($guarded)->toBe(['__ske/queue']);
    });
});

it('sends the log to stderr, because the image has no file to write', function () {
    withPlatform(present: true, callback: function () {
        $app = applicationFor(['logging' => ['default' => 'stack']]);

        registerProvider($app);

        expect($app->make('config')->get('logging.default'))->toBe('stderr')
            ->and($app->make('config')->get('logging.channels.stderr.with.stream'))->toBe('php://stderr');
    });
});

it('leaves a deployment that chose its own log channel alone', function () {
    withPlatform(present: true, callback: function () {
        $app = applicationFor(['logging' => ['default' => 'papertrail']]);

        registerProvider($app);

        expect($app->make('config')->get('logging.default'))->toBe('papertrail')
            ->and($app->make('config')->get('logging.channels.stderr'))->not->toBeNull();
    });
});

it('clears the half-credentials that would be rejected', function () {
    // The execution role injects a key, a secret and a session token; Laravel's
    // config reads the first two and not the third, so the SDK would send an
    // incomplete pair. Clearing them hands the decision to the credential chain.
    withPlatform(present: true, callback: function () {
        $app = applicationFor([
            'queue' => ['connections' => ['sqs' => ['key' => 'AKIA', 'secret' => 'shh', 'queue' => 'default']]],
            'filesystems' => ['disks' => ['s3' => ['key' => 'AKIA', 'secret' => 'shh']]],
            'mail' => ['mailers' => ['ses' => ['key' => 'AKIA', 'secret' => 'shh']]],
        ]);

        registerProvider($app);

        $config = $app->make('config');

        expect($config->get('queue.connections.sqs.key'))->toBeNull()
            ->and($config->get('queue.connections.sqs.secret'))->toBeNull()
            ->and($config->get('filesystems.disks.s3.key'))->toBeNull()
            ->and($config->get('mail.mailers.ses.secret'))->toBeNull()
            // Untouched: the module clears credentials, not configuration.
            ->and($config->get('queue.connections.sqs.queue'))->toBe('default');
    });
});

it('does not invent credentials for a connection that has none', function () {
    withPlatform(present: true, callback: function () {
        $app = applicationFor(['queue' => ['connections' => ['sqs' => ['queue' => 'default']]]]);

        registerProvider($app);

        expect($app->make('config')->has('queue.connections.sqs.key'))->toBeFalse();
    });
});

// --- the platform's queue connection ------------------------------------

it('connects the ske queue to Cloud Tasks on GCP', function () {
    withPlatform(present: true, callback: function () {
        $_SERVER['SKE_PROVIDER'] = 'gcp';
        $_SERVER['SKE_GCP_PROJECT'] = 'acme-prod';
        $_SERVER['SKE_REGION'] = 'europe-west1';
        $_SERVER['SKE_QUEUE_PREFIX'] = 'ske-shop-prod--';

        $app = applicationFor();
        $app->singleton('queue', fn ($app) => new QueueManager($app));

        registerProvider($app);

        expect($app->make('config')->get('queue.connections.ske'))->toBe([
            'driver' => CloudTasksConnector::DRIVER,
            'project' => 'acme-prod',
            'location' => 'europe-west1',
            'prefix' => 'ske-shop-prod--',
            'queue' => 'default',
            'after_commit' => false,
        ]);

        $queue = $app->make('queue')->connection('ske');

        expect($queue)->toBeInstanceOf(CloudTasksQueue::class)
            ->and($queue->queuePath('emails'))->toBe('projects/acme-prod/locations/europe-west1/queues/ske-shop-prod--emails');
    });
});

it('puts a job released on GCP back on its Cloud Tasks queue', function () {
    withPlatform(present: true, callback: function () {
        $_SERVER['SKE_PROVIDER'] = 'gcp';
        $_SERVER['SKE_GCP_PROJECT'] = 'acme-prod';
        $_SERVER['SKE_REGION'] = 'europe-west1';
        $_SERVER['SKE_QUEUE_PREFIX'] = 'ske-shop-prod--';

        $http = new HttpFactory;
        $http->fake([
            'http://metadata.google.internal/*' => $http->response(['access_token' => 't', 'expires_in' => 3599]),
            'https://cloudtasks.googleapis.com/*' => $http->response(['name' => 'projects/p/locations/l/queues/q/tasks/9']),
        ]);

        $app = applicationFor();
        $app->instance(HttpFactory::class, $http);
        $app->singleton('queue', fn ($app) => new QueueManager($app));
        $app->instance(Worker::class, new class extends Worker
        {
            public function __construct() {}

            public function process($connectionName, $job, WorkerOptions $options): void
            {
                $job->release(60);
            }
        });

        registerProvider($app);

        $outcome = $app->make(QueueRunner::class)->process([
            ['message_id' => 'task-1', 'body' => '{"uuid":"u-1"}', 'queue' => 'ske-shop-prod--emails'],
        ])[0];

        $requests = $http->recorded(fn ($request): bool => str_ends_with($request->url(), '/tasks'));

        expect($outcome->deleted)->toBeTrue()
            ->and($requests)->toHaveCount(1)
            ->and($requests->first()[0]->url())->toEndWith('/queues/ske-shop-prod--emails/tasks');
    });
});

it('connects the ske queue to the environment SQS queues on AWS', function () {
    withPlatform(present: true, callback: function () {
        $_SERVER['SKE_PROVIDER'] = 'aws';
        $_SERVER['SQS_PREFIX'] = 'https://sqs.eu-west-1.amazonaws.com/123456789012';
        $_SERVER['SQS_QUEUE'] = 'default';
        $_SERVER['SQS_SUFFIX'] = '--ske-shop-prod';
        $_SERVER['AWS_REGION'] = 'eu-west-1';

        $app = applicationFor();

        registerProvider($app);

        expect($app->make('config')->get('queue.connections.ske'))->toBe([
            'driver' => SqsConnector::DRIVER,
            'prefix' => 'https://sqs.eu-west-1.amazonaws.com/123456789012',
            'queue' => 'default',
            'suffix' => '--ske-shop-prod',
            'region' => 'eu-west-1',
            'after_commit' => false,
        ]);
    });
});

it('defines no ske queue when the deploy names no provider', function () {
    withPlatform(present: true, callback: function () {
        unset($_SERVER['SKE_PROVIDER']);
        putenv('SKE_PROVIDER');

        $app = applicationFor();

        registerProvider($app);

        expect($app->make('config')->has('queue.connections.ske'))->toBeFalse();
    });
});

// --- the HTTP kernel -----------------------------------------------------

it('teaches the HTTP kernel to trust the platform proxy and correlate requests', function () {
    withPlatform(present: true, callback: function () {
        $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';

        $app = applicationFor();
        $kernel = fakeHttpKernel();
        $app->instance(HttpKernel::class, $kernel);

        registerProvider($app)->boot();

        expect($kernel->prepended)->toBe([TrustPlatformProxy::class])
            ->and($kernel->pushed)->toBe([CorrelateRequests::class]);
    });
});

it('does not reach for an HTTP kernel in a console process', function () {
    // A console command or the scheduler boots the console kernel and never binds
    // an HTTP one; asking for it would abort the worker before it ran a job.
    withPlatform(present: true, callback: function () {
        $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'true';

        $app = applicationFor();

        expect(fn () => registerProvider($app)->boot())->not->toThrow(Throwable::class);
    });
});

/**
 * A kernel that records what was prepended rather than running it through a
 * middleware pipeline. Extends the concrete class because that is what the
 * provider resolves — the contract does not declare prependMiddleware().
 */
final class RecordingHttpKernel extends HttpKernel
{
    /** @var list<string> */
    public array $prepended = [];

    /** @var list<string> */
    public array $pushed = [];

    public function __construct()
    {
        // The parent wants an Application and a Router; this test does not
        // exercise the pipeline, so the fields stay uninitialised.
    }

    public function prependMiddleware($middleware): static
    {
        $this->prepended[] = $middleware;

        return $this;
    }

    public function pushMiddleware($middleware): static
    {
        $this->pushed[] = $middleware;

        return $this;
    }
}

function fakeHttpKernel(): RecordingHttpKernel
{
    return new RecordingHttpKernel;
}
