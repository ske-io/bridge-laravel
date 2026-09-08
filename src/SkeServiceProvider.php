<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Ske\Bridge\Laravel\Console\CommandRunner;
use Ske\Bridge\Laravel\Handlers\CliHandler;
use Ske\Bridge\Laravel\Handlers\QueueHandler;
use Ske\Bridge\Laravel\Handlers\SchedulerHandler;
use Ske\Bridge\Laravel\Http\Middleware\EnsureSkeInternal;
use Ske\Bridge\Laravel\Http\TrustPlatformProxy;
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

        $this->app->make(HttpKernel::class)->prependMiddleware(TrustPlatformProxy::class);
    }

    private function registerRoutes(): void
    {
        Route::middleware(EnsureSkeInternal::class)->group(function (): void {
            Route::post('/__ske/cli', CliHandler::class);
            Route::post('/__ske/queue', QueueHandler::class);
            Route::post('/__ske/scheduler', SchedulerHandler::class);
        });

        // Reachable from outside on purpose: this is what the load balancer
        // probes, and the adapter allows exactly this one path through.
        Route::get('/__ske/health', fn () => response('OK'));
    }

    /**
     * The image has no log files to read, so everything goes to stderr, which
     * the adapter forwards to the platform's log.
     */
    private function configureLogging(): void
    {
        $this->app['config']->set('logging.channels.stderr', [
            'driver' => 'monolog',
            'handler' => \Monolog\Handler\StreamHandler::class,
            'with' => ['stream' => 'php://stderr'],
            'level' => 'debug',
        ]);

        if ($this->app['config']->get('logging.default') === 'stack') {
            $this->app['config']->set('logging.default', 'stderr');
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
            if ($this->app['config']->has("{$path}.key")) {
                $this->app['config']->set("{$path}.key", null);
                $this->app['config']->set("{$path}.secret", null);
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
