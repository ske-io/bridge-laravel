<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Mail\MailManager;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Monolog\Handler\StreamHandler;
use Ske\Bridge\Laravel\Console\CommandRunner;
use Ske\Bridge\Laravel\Handlers\CliHandler;
use Ske\Bridge\Laravel\Handlers\QueueHandler;
use Ske\Bridge\Laravel\Handlers\SchedulerHandler;
use Ske\Bridge\Laravel\Http\Middleware\CorrelateRequests;
use Ske\Bridge\Laravel\Http\Middleware\EnsureSkeInternal;
use Ske\Bridge\Laravel\Http\TrustPlatformProxy;
use Ske\Bridge\Laravel\Mail\SkeMailTransport;
use Ske\Bridge\Laravel\Queue\QueueRunner;
use Ske\Bridge\Laravel\Runtime\Environment;

/**
 * Wires the four things a deployment needs: HTTP, queue, CLI and scheduler.
 *
 * Everything here is inert off-platform. A developer running the same
 * application locally gets none of it, which is what keeps the package safe to
 * require unconditionally.
 */
final class SkeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! self::runningOnPlatform()) {
            return;
        }

        $this->configureLogging();
        $this->configureAwsCredentials();
        $this->registerMailTransport();

        $this->app->singleton(
            CommandRunner::class,
            fn ($app): CommandRunner => new CommandRunner($app->make(ConsoleKernel::class)),
        );

        $this->app->singleton(
            QueueRunner::class,
            fn ($app): QueueRunner => new QueueRunner($app->make(Worker::class), $app),
        );
    }

    public function boot(): void
    {
        if (! self::runningOnPlatform()) {
            return;
        }

        $this->trustPlatformProxy();
        $this->registerRoutes();
    }

    /**
     * Requests arrive through the platform's load balancer and the adapter, so
     * the forwarded scheme, host and client address are the real ones. Pushed
     * onto the HTTP kernel rather than left to the application's own middleware
     * list, because a deployment should not have to know this.
     */
    private function trustPlatformProxy(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        $kernel = $this->app->make(HttpKernel::class);
        $kernel->prependMiddleware(TrustPlatformProxy::class);
        $kernel->pushMiddleware(CorrelateRequests::class);
    }

    /**
     * Register the SKE mail transport.
     *
     * The mailer config entry must exist before MailManager::getConfig() is
     * called — even when the application's config is cached — otherwise
     * "Mailer [ske] is not defined" is thrown.  We write it to the config
     * repository in register() rather than merging a config file, because
     * bootstrap.php has already seeded $_ENV from the process environment
     * by the time register() runs, and the MAIL_MAILER value is known.
     *
     * The custom transport creator is registered via extend() on the
     * MailManager singleton. Because MailServiceProvider is deferred, the
     * manager may not exist yet; we register the extension on the
     * singleton's definition so it runs when the manager is first resolved.
     */
    private function registerMailTransport(): void
    {
        $endpoint = Environment::get('SKE_MAIL_ENDPOINT');
        $serviceId = Environment::get('SKE_MAIL_SERVICE_ID');
        $token = Environment::get('SKE_MAIL_TOKEN');

        if (! $endpoint || ! $serviceId || ! $token) {
            return;
        }

        // Ensure the mailer config entry exists. This survives even when
        // config:cache has already been loaded because we write directly
        // to the in-memory repository.
        $this->app['config']->set('mail.mailers.ske', ['transport' => 'ske']);

        // Register the transport creator on the deferred MailManager singleton.
        // We override the singleton definition to wrap the original factory
        // with our extend() call, so the creator is present from the moment
        // the manager is first resolved — no timing dependency on boot().
        $this->app->extend('mail.manager', function (MailManager $manager) use ($endpoint, $serviceId, $token): MailManager {
            $manager->extend('ske', fn (): SkeMailTransport => new SkeMailTransport($endpoint, $serviceId, $token));

            return $manager;
        });
    }

    private function registerRoutes(): void
    {
        Route::middleware(EnsureSkeInternal::class)->group(function (): void {
            Route::post('/__ske/cli', CliHandler::class);
            Route::post('/__ske/queue', QueueHandler::class);
            Route::post('/__ske/scheduler', SchedulerHandler::class);
        });
    }

    /**
     * The image has no log files to read, so everything goes to stderr, which
     * the adapter forwards to the platform's log.
     */
    private function configureLogging(): void
    {
        config(['logging.channels.stderr' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => ['stream' => 'php://stderr'],
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
     */
    private function configureAwsCredentials(): void
    {
        foreach (['queue.connections.sqs', 'filesystems.disks.s3', 'mail.mailers.ses'] as $path) {
            if (config()->has("{$path}.key")) {
                config(["{$path}.key" => null, "{$path}.secret" => null]);
            }
        }
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
