<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Ske\Bridge\Laravel\Console\CommandRunner;
use Ske\Bridge\Laravel\Http\Middleware\CorrelateRequests;
use Ske\Bridge\Laravel\Http\Middleware\EnsureSkeInternal;
use Ske\Bridge\Laravel\Http\TrustPlatformProxy;
use Ske\Bridge\Laravel\Queue\QueueRunner;
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
 * The smallest application the provider can be pointed at: a config repository
 * and a router, which is all it touches. Deliberately not a booted framework —
 * what is under test is the wiring, not Laravel.
 *
 * @param  array<string, mixed>  $config
 */
function applicationFor(array $config = []): Application
{
    $app = new Application(sys_get_temp_dir());
    $app->instance('config', new Repository($config));
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

        expect($app->bound(CommandRunner::class))->toBeFalse()
            ->and($app->bound(QueueRunner::class))->toBeFalse()
            ->and(registeredRoutes($app))->toBe([])
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

// --- on the platform -----------------------------------------------------

it('binds the two runners as singletons', function () {
    withPlatform(present: true, callback: function () {
        $app = applicationFor();
        $app->instance(ConsoleKernel::class, fakeConsoleKernel());

        registerProvider($app);

        expect($app->isShared(CommandRunner::class))->toBeTrue()
            ->and($app->isShared(QueueRunner::class))->toBeTrue()
            ->and($app->make(CommandRunner::class))->toBe($app->make(CommandRunner::class));
    });
});

it('registers the three internal routes the adapter calls', function () {
    withPlatform(present: true, callback: function () {
        $app = applicationFor();

        registerProvider($app)->boot();

        $routes = collect(registeredRoutes($app))
            ->mapWithKeys(fn ($route) => [$route->uri() => [$route->methods(), $route->middleware()]]);

        expect($routes->keys()->all())->toBe([
            '__ske/cli', '__ske/queue', '__ske/scheduler',
        ])
            ->and($routes['__ske/cli'][0])->toContain('POST')
            ->and($routes['__ske/queue'][0])->toContain('POST')
            ->and($routes['__ske/scheduler'][0])->toContain('POST');
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

it('guards all internal routes', function () {
    withPlatform(present: true, callback: function () {
        $app = applicationFor();

        registerProvider($app)->boot();

        $guarded = collect(registeredRoutes($app))
            ->filter(fn ($route) => in_array(EnsureSkeInternal::class, $route->middleware(), true))
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all();

        expect($guarded)->toBe(['__ske/cli', '__ske/queue', '__ske/scheduler']);
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
    // A queue or scheduler invocation boots the console kernel and never binds
    // an HTTP one; asking for it would abort the worker before it ran a job.
    withPlatform(present: true, callback: function () {
        $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'true';

        $app = applicationFor();

        expect(fn () => registerProvider($app)->boot())->not->toThrow(Throwable::class);
    });
});

function fakeConsoleKernel(): ConsoleKernel
{
    return new class implements ConsoleKernel
    {
        public function bootstrap(): void {}

        public function handle($input, $output = null): int
        {
            return 0;
        }

        public function terminate($input, $status): void {}

        /**
         * @param  array<string, mixed>  $parameters
         */
        public function call($command, array $parameters = [], $outputBuffer = null): int
        {
            return 0;
        }

        /**
         * @param  array<string, mixed>  $parameters
         */
        public function queue($command, array $parameters = []): PendingDispatch
        {
            throw new RuntimeException('Not implemented in test double');
        }

        /** @return array<string, Command> */
        public function all(): array
        {
            return [];
        }

        public function output(): string
        {
            return '';
        }
    };
}

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
