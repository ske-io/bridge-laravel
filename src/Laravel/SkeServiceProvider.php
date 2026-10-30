<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel;

use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inertia\Ssr\Gateway;
use Inertia\Ssr\HttpGateway;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;
use RuntimeException;
use Ske\Bridge\Laravel\Handlers\QueueHandler;
use Ske\Bridge\Laravel\Http\Controllers\SignedStorageUrlController;
use Ske\Bridge\Laravel\Http\Middleware\CorrelateRequests;
use Ske\Bridge\Laravel\Http\Middleware\EnsureSkeInternal;
use Ske\Bridge\Laravel\Inertia\InertiaSsrGateway;
use Ske\Bridge\Laravel\Queue\CloudTasksConnector;
use Ske\Bridge\Laravel\Queue\CloudTasksQueue;
use Ske\Bridge\Laravel\Queue\QueueRunner;
use Ske\Bridge\Laravel\Queue\SqsConnector;
use Ske\Bridge\Laravel\Runtime\PlatformMaintenanceMode;
use Ske\Bridge\Runtime\Environment;

/**
 * Wires what a deployment needs: HTTP, delivered queue jobs, and the setup
 * every process gets (logging, the queue connection, maintenance mode). The
 * platform runs commands and the scheduler as plain `php artisan` processes,
 * which boot through here like any other.
 *
 * Everything here is inert off-platform. A developer running the same
 * application locally gets none of it, which is what keeps the package safe to
 * require unconditionally. The one exception is the upload endpoint, which the
 * application's own frontend calls and so must answer wherever it runs.
 */
final class SkeServiceProvider extends ServiceProvider
{
    private const CONFIG = __DIR__.'/../../config/ske.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG, 'ske');

        if (! self::runningOnPlatform()) {
            return;
        }

        $this->configureLogging();
        $this->configureAwsCredentials();
        $this->configureSesRegion();
        $this->configureQueueConnection();

        $this->app->singleton(MaintenanceMode::class, fn (): MaintenanceMode => new PlatformMaintenanceMode);

        $this->app->singleton(QueueRunner::class, fn ($app): QueueRunner => $this->queueRunner($app));
    }

    public function boot(): void
    {
        $this->publishes([self::CONFIG => $this->app->configPath('ske.php')], 'ske-config');
        $this->registerUploadRoute();

        if (! self::runningOnPlatform()) {
            return;
        }

        $this->configureInertiaSsr();
        $this->correlateRequests();
        $this->registerRoutes();

        // The platform delivers queue jobs through the internal route and
        // probes /up for readiness; maintenance only closes the application
        // to its visitors.
        PreventRequestsDuringMaintenance::except(['__ske/*', 'up']);
    }

    private function configureInertiaSsr(): void
    {
        $enabled = Environment::get('SKE_SSR_ENABLED');

        if ($enabled === null) {
            return;
        }

        if (! filter_var($enabled, FILTER_VALIDATE_BOOLEAN)) {
            if (interface_exists(Gateway::class)) {
                config(['inertia.ssr.enabled' => false]);
            }

            return;
        }

        if (! class_exists(HttpGateway::class)) {
            throw new RuntimeException('Inertia SSR is enabled, but inertiajs/inertia-laravel is not installed.');
        }

        if (! config('inertia.ssr.enabled')
            || rtrim((string) config('inertia.ssr.url'), '/') !== rtrim(Environment::get('INERTIA_SSR_URL') ?? 'http://127.0.0.1:13714', '/')) {
            throw new RuntimeException('config/inertia.php must honor INERTIA_SSR_ENABLED and INERTIA_SSR_URL for this environment.');
        }

        $this->app->singleton(HttpGateway::class, InertiaSsrGateway::class);
        $this->app->bind(Gateway::class, HttpGateway::class);
    }

    /**
     * Tags each request's logs with the platform's request ID. Pushed onto the
     * HTTP kernel rather than left to the application's own middleware list,
     * because a deployment should not have to know this.
     *
     * Proxy trust is deliberately left to the application. The platform hands
     * PHP the address the platform saw connect as REMOTE_ADDR, with the real
     * scheme and host, so with no trusted proxies Request::ip() and url() are
     * already right. An application behind a CDN trusts that CDN's ranges with
     * its usual trustProxies() configuration, as it would behind nginx.
     */
    private function correlateRequests(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        $this->app->make(HttpKernel::class)->pushMiddleware(CorrelateRequests::class);
    }

    private function registerRoutes(): void
    {
        Route::post('/__ske/queue', QueueHandler::class)->middleware(EnsureSkeInternal::class);
    }

    /**
     * The endpoint the frontend asks for a signed upload URL. Registered on
     * and off the platform: the frontend calls it wherever the application
     * runs, and it only signs with the application's own disk.
     */
    private function registerUploadRoute(): void
    {
        $url = config('ske.uploads.url');

        if (! config('ske.uploads.enabled') || ! is_string($url)) {
            return;
        }

        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::post($url, SignedStorageUrlController::class)
            ->middleware(config('ske.uploads.middleware', []));
    }

    /**
     * The image has no log files to read, so everything goes to stderr, which
     * the platform forwards to its log viewer. Each record is one JSON
     * line, its exception's stack trace included, which the logs page reads
     * into level, message, context and exception. A LOG_STDERR_FORMATTER
     * the deployment sets still wins.
     */
    private function configureLogging(): void
    {
        config(['logging.channels.stderr' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => ['stream' => 'php://stderr'],
            'formatter' => Environment::get('LOG_STDERR_FORMATTER') ?? JsonFormatter::class,
            'formatter_with' => ['includeStacktraces' => true],
            'processors' => [PsrLogMessageProcessor::class],
            'level' => 'debug',
        ]]);

        if (config('logging.default') === 'stack') {
            config(['logging.default' => 'stderr']);
        }
    }

    /**
     * The execution role injects a key, a secret and a session token. Laravel's
     * own config reads the first two through env() but not the third, so the SDK
     * would send an incomplete pair and be rejected. Clearing them hands the
     * decision back to the SDK's credential chain, which reads all three.
     *
     * Every AWS client Laravel builds from config is covered: SES reads
     * `services.ses` (under any `ses` or `ses-v2` mailer's own keys), and any
     * SQS connection, S3 disk, DynamoDB cache store or failed job store reads
     * its own entry. Only a key that is the role's is cleared, so a key pair
     * the application set itself still wins.
     */
    private function configureAwsCredentials(): void
    {
        $roleKey = Environment::get('AWS_ACCESS_KEY_ID');

        if ($roleKey === null) {
            return;
        }

        $paths = ['services.ses', 'queue.failed'];

        foreach (['queue.connections', 'filesystems.disks', 'cache.stores', 'mail.mailers'] as $group) {
            foreach (array_keys((array) config($group, [])) as $name) {
                $paths[] = "{$group}.{$name}";
            }
        }

        foreach ($paths as $path) {
            if (config("{$path}.key") === $roleKey) {
                config(["{$path}.key" => null, "{$path}.secret" => null]);
            }
        }
    }

    /**
     * A linked SMTP provider's SES identity is verified in its own region,
     * which the deploy sets as AWS_SES_REGION alongside MAIL_MAILER=ses. Laravel
     * reads the SES region from AWS_DEFAULT_REGION, the function's own region,
     * so mail would go to a region where the identity does not exist. The
     * platform's region wins: the deploy only sets it for its own provider.
     */
    private function configureSesRegion(): void
    {
        $region = Environment::get('AWS_SES_REGION');

        if ($region === null) {
            return;
        }

        config(['services.ses.region' => $region]);

        foreach ((array) config('mail.mailers', []) as $name => $mailer) {
            if (in_array($mailer['transport'] ?? null, ['ses', 'ses-v2'], true)) {
                config(["mail.mailers.{$name}.region" => $region]);
            }
        }
    }

    /**
     * The runner for delivered jobs: it records failures with the application's
     * failed job provider, and on GCP puts a released job back on its Cloud
     * Tasks queue so its delay is kept. It knows each provider's queue naming
     * so a job reports the application's queue name. The worker is the
     * framework's `queue.worker`: its constructor needs the maintenance check
     * that only that binding supplies, so the class cannot be autowired.
     */
    private function queueRunner(Application $app): QueueRunner
    {
        $provider = Environment::get('SKE_PROVIDER');
        $requeue = $provider === 'gcp' ? $app->make('queue')->connection('ske') : null;

        return new QueueRunner(
            worker: $app->make('queue.worker'),
            container: $app,
            failer: $app->bound('queue.failer') ? $app->make('queue.failer') : null,
            requeue: $requeue instanceof CloudTasksQueue ? $requeue : null,
            queuePrefix: $provider === 'gcp' ? (Environment::get('SKE_QUEUE_PREFIX') ?? '') : '',
            queueSuffix: $provider === 'aws' ? (Environment::get('SQS_SUFFIX') ?? '') : '',
        );
    }

    /**
     * The `ske` connection: the platform's own queue, which the deploy sets as
     * QUEUE_CONNECTION and which QueueRunner names when it runs a delivered
     * job. It sends to SQS on AWS and to Cloud Tasks on GCP, configured from the
     * variables each provider's deploy sets. SQS needs the AWS SDK, which the
     * bridge does not require; without it the connection refuses with what to
     * do.
     */
    private function configureQueueConnection(): void
    {
        $connection = match (Environment::get('SKE_PROVIDER')) {
            'aws' => [
                'driver' => SqsConnector::DRIVER,
                'prefix' => Environment::get('SQS_PREFIX'),
                'queue' => Environment::get('SQS_QUEUE') ?? 'default',
                'suffix' => Environment::get('SQS_SUFFIX'),
                'region' => Environment::get('AWS_REGION') ?? Environment::get('AWS_DEFAULT_REGION'),
                'after_commit' => false,
            ],
            'gcp' => [
                'driver' => CloudTasksConnector::DRIVER,
                'project' => Environment::get('SKE_GCP_PROJECT'),
                'location' => Environment::get('SKE_REGION'),
                'prefix' => Environment::get('SKE_QUEUE_PREFIX'),
                'queue' => 'default',
                'after_commit' => false,
            ],
            default => null,
        };

        if ($connection === null) {
            return;
        }

        config(['queue.connections.ske' => $connection]);

        $this->callAfterResolving('queue', function (QueueManager $manager): void {
            $manager->addConnector(
                CloudTasksConnector::DRIVER,
                fn (): CloudTasksConnector => new CloudTasksConnector($this->app->make(HttpFactory::class)),
            );
            $manager->addConnector(SqsConnector::DRIVER, fn (): SqsConnector => new SqsConnector);
        });
    }

    /**
     * Read through Environment rather than env(): a queue or console worker sees
     * these only in the real process environment, which Laravel's repository
     * does not always cover.
     */
    public static function runningOnPlatform(): bool
    {
        return Environment::get('SKE_PROJECT_TYPE') !== null
            || Environment::get('SKE_ENVIRONMENT') !== null;
    }
}
