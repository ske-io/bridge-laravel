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
use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Illuminate\Mail\MailManager;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;
use Ske\Bridge\Laravel\Http\Middleware\CorrelateRequests;
use Ske\Bridge\Laravel\Http\Middleware\EnsureSkeInternal;
use Ske\Bridge\Laravel\Queue\CloudTasksConnector;
use Ske\Bridge\Laravel\Queue\CloudTasksQueue;
use Ske\Bridge\Laravel\Queue\QueueRunner;
use Ske\Bridge\Laravel\Queue\SqsConnector;
use Ske\Bridge\Laravel\Runtime\PlatformMaintenanceMode;
use Ske\Bridge\Laravel\SkeServiceProvider;

/**
 * The two variables the platform sets on every function it creates. Both
 * are cleared from all three sources Environment reads, so an off-platform test
 * is off-platform whatever the machine running the suite happens to export.
 */
const PLATFORM_VARIABLES = ['SKE_PROJECT_TYPE', 'SKE_ENVIRONMENT'];

/**
 * The AWS variables the provider reads, cleared the same way: a machine with
 * its own AWS key or region exported must not decide what a test sees.
 */
const AWS_VARIABLES = ['AWS_ACCESS_KEY_ID', 'AWS_SES_REGION', 'AWS_REGION', 'AWS_DEFAULT_REGION'];

/**
 * Runs a callback with the platform either announced or absent, restoring the
 * real environment — and the container and facade globals the application
 * constructor claims — afterwards.
 */
function withPlatform(bool $present, Closure $callback): void
{
    $server = $_SERVER;
    $env = $_ENV;
    $variables = [...PLATFORM_VARIABLES, ...AWS_VARIABLES];
    $process = array_map(
        fn (string $key): string|false => getenv($key),
        $variables,
    );
    $container = Container::getInstance();

    foreach ($variables as $key) {
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
        foreach ($variables as $i => $key) {
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

it('recognises either variable the platform sets', function (string $variable) {
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

it('registers the internal route the platform calls', function () {
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

it('keeps the platform routes and the readiness probe open during maintenance', function () {
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

it('logs one JSON record per line, with the exception\'s stack trace', function () {
    withPlatform(present: true, callback: function () {
        $app = applicationFor(['logging' => ['default' => 'stack']]);
        registerProvider($app);

        $channel = (new LogManager($app))->channel('stderr');
        $monolog = $channel instanceof Logger ? $channel->getLogger() : null;
        $handler = $monolog instanceof Monolog\Logger ? $monolog->getHandlers()[0] : null;

        $record = new LogRecord(
            new DateTimeImmutable,
            'production',
            Level::Error,
            'Order {id} could not be charged',
            ['id' => 17, 'exception' => new RuntimeException('card declined')],
        );

        foreach ($monolog instanceof Monolog\Logger ? $monolog->getProcessors() : [] as $processor) {
            $record = $processor($record);
        }

        $formatter = $handler instanceof FormattableHandlerInterface ? $handler->getFormatter() : null;
        $line = json_decode((string) $formatter?->format($record), true);

        expect($formatter)->toBeInstanceOf(JsonFormatter::class)
            ->and($line)->toMatchArray(['message' => 'Order 17 could not be charged', 'level_name' => 'ERROR'])
            ->and(data_get($line, 'context.exception.class'))->toBe(RuntimeException::class)
            ->and(data_get($line, 'context.exception.trace'))->not->toBeEmpty();
    });
});

it('lets the deployment choose another formatter', function () {
    withPlatform(present: true, callback: function () {
        $_SERVER['LOG_STDERR_FORMATTER'] = LineFormatter::class;
        $app = applicationFor(['logging' => ['default' => 'stack']]);

        registerProvider($app);

        expect($app->make('config')->get('logging.channels.stderr.formatter'))->toBe(LineFormatter::class);
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
        $_SERVER['AWS_ACCESS_KEY_ID'] = 'ASIAROLE';
        $role = ['key' => 'ASIAROLE', 'secret' => 'shh'];
        $app = applicationFor([
            'services' => ['ses' => $role + ['region' => 'us-east-1']],
            'queue' => [
                'connections' => ['sqs' => $role + ['queue' => 'default']],
                'failed' => $role + ['driver' => 'dynamodb'],
            ],
            'filesystems' => ['disks' => ['s3' => $role, 'uploads' => $role]],
            'cache' => ['stores' => ['dynamodb' => $role]],
            'mail' => ['mailers' => ['ses' => $role, 'ses-v2' => ['transport' => 'ses-v2']]],
        ]);

        registerProvider($app);

        $config = $app->make('config');

        foreach (['services.ses', 'queue.connections.sqs', 'queue.failed', 'filesystems.disks.s3', 'filesystems.disks.uploads', 'cache.stores.dynamodb', 'mail.mailers.ses'] as $path) {
            expect($config->get("{$path}.key"))->toBeNull("{$path}.key")
                ->and($config->get("{$path}.secret"))->toBeNull("{$path}.secret");
        }

        // Untouched: the module clears credentials, not configuration.
        expect($config->get('queue.connections.sqs.queue'))->toBe('default')
            ->and($config->get('services.ses.region'))->toBe('us-east-1')
            ->and($config->has('mail.mailers.ses-v2.key'))->toBeFalse();
    });
});

it('builds both SES transports without explicit credentials', function (string $mailer) {
    // The SES transports merge `services.ses` under the mailer's own entry, and
    // pass credentials to the client only when that merge has a key and a
    // secret. The SDK is not installed here, so the merge is checked the way
    // MailManager hands it to the client.
    withPlatform(present: true, callback: function () use ($mailer) {
        $_SERVER['AWS_ACCESS_KEY_ID'] = 'ASIAROLE';
        $app = applicationFor([
            'services' => ['ses' => ['key' => 'ASIAROLE', 'secret' => 'shh', 'region' => 'us-east-1']],
            'mail' => ['mailers' => [$mailer => ['transport' => $mailer]]],
        ]);

        registerProvider($app);

        $config = $app->make('config');
        $merged = array_merge($config->get('services.ses'), $config->get("mail.mailers.{$mailer}"));
        $client = (fn (array $config): array => $this->addSesCredentials($config))
            ->call(new MailManager($app), $merged);

        expect($client)->not->toHaveKey('credentials')
            ->and($client['region'])->toBe('us-east-1');
    });
})->with(['ses', 'ses-v2']);

it('keeps a key pair the application set itself', function () {
    withPlatform(present: true, callback: function () {
        $_SERVER['AWS_ACCESS_KEY_ID'] = 'ASIAROLE';
        $app = applicationFor([
            'services' => ['ses' => ['key' => 'AKIAOWN', 'secret' => 'own']],
            'filesystems' => ['disks' => ['s3' => ['key' => 'AKIAOWN', 'secret' => 'own']]],
        ]);

        registerProvider($app);

        $config = $app->make('config');

        expect($config->get('services.ses'))->toBe(['key' => 'AKIAOWN', 'secret' => 'own'])
            ->and($config->get('filesystems.disks.s3'))->toBe(['key' => 'AKIAOWN', 'secret' => 'own']);
    });
});

it('leaves credentials alone where no execution role injects a key', function () {
    // On GCP there is no role key: a pair in config is the application's own.
    withPlatform(present: true, callback: function () {
        unset($_SERVER['AWS_ACCESS_KEY_ID'], $_ENV['AWS_ACCESS_KEY_ID']);
        $app = applicationFor(['filesystems' => ['disks' => ['s3' => ['key' => 'AKIAOWN', 'secret' => 'own']]]]);

        registerProvider($app);

        expect($app->make('config')->get('filesystems.disks.s3.key'))->toBe('AKIAOWN');
    });
});

it('sets the SES region from the linked provider', function () {
    withPlatform(present: true, callback: function () {
        $_SERVER['AWS_SES_REGION'] = 'eu-central-1';
        $app = applicationFor();

        registerProvider($app);

        expect($app->make('config')->get('services.ses.region'))->toBe('eu-central-1');
    });
});

it('sends SES mail from the linked provider region', function () {
    // The function runs in eu-west-1; the identity is verified in us-east-1.
    withPlatform(present: true, callback: function () {
        $_SERVER['AWS_DEFAULT_REGION'] = 'eu-west-1';
        $_SERVER['AWS_SES_REGION'] = 'us-east-1';
        $app = applicationFor([
            'services' => ['ses' => ['key' => null, 'secret' => null, 'region' => 'eu-west-1']],
            'mail' => ['mailers' => [
                'ses' => ['transport' => 'ses'],
                'ses-v2' => ['transport' => 'ses-v2', 'region' => 'eu-west-1'],
                'smtp' => ['transport' => 'smtp'],
            ]],
        ]);

        registerProvider($app);

        $config = $app->make('config');

        expect($config->get('services.ses.region'))->toBe('us-east-1')
            ->and($config->get('mail.mailers.ses.region'))->toBe('us-east-1')
            ->and($config->get('mail.mailers.ses-v2.region'))->toBe('us-east-1')
            ->and($config->has('mail.mailers.smtp.region'))->toBeFalse();

        // What the transport hands the SES client: the mailer merged over
        // services.ses, as MailManager does.
        foreach (['ses', 'ses-v2'] as $mailer) {
            $merged = array_merge($config->get('services.ses'), $config->get("mail.mailers.{$mailer}"));

            expect($merged['region'])->toBe('us-east-1');
        }
    });
});

it('leaves the SES region alone without a linked provider', function () {
    withPlatform(present: true, callback: function () {
        unset($_SERVER['AWS_SES_REGION'], $_ENV['AWS_SES_REGION']);
        $app = applicationFor([
            'services' => ['ses' => ['region' => 'eu-west-1']],
            'mail' => ['mailers' => ['ses' => ['transport' => 'ses']]],
        ]);

        registerProvider($app);

        $config = $app->make('config');

        expect($config->get('services.ses.region'))->toBe('eu-west-1')
            ->and($config->has('mail.mailers.ses.region'))->toBeFalse();
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
        $app->instance('queue.worker', new class extends Worker
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

it('correlates requests and leaves proxy trust to the application', function () {
    // The application decides which proxies to trust, as it would behind nginx;
    // the bridge adds nothing in front of its middleware.
    withPlatform(present: true, callback: function () {
        $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';

        $app = applicationFor();
        $kernel = fakeHttpKernel();
        $app->instance(HttpKernel::class, $kernel);

        registerProvider($app)->boot();

        expect($kernel->prepended)->toBe([])
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
 * A kernel that records the middleware added to it rather than running it
 * through a pipeline. Extends the concrete class because that is what the
 * provider resolves — the contract does not declare the middleware methods.
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
