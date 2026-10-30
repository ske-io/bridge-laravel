<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Queue\NullQueue;
use Illuminate\Support\Facades\Facade;
use Ske\Bridge\Laravel\Queue\QueueRunner;
use Ske\Bridge\Laravel\SkeServiceProvider;

/*
 * The queue runner as a deployed application resolves it: a real Laravel
 * application, configured and with the framework's own providers registered,
 * and the bridge registered on top as package discovery would. The other
 * provider tests use a bare container, which cannot show whether what the
 * provider asks the framework for is something the framework provides.
 */

/** A job that only records that it ran. */
final class FlagJob implements ShouldQueue
{
    use Queueable;

    public static bool $ran = false;

    public function handle(): void
    {
        self::$ran = true;
    }
}

/** A job that always throws, with attempts left so it is released. */
final class ThrowingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(): void
    {
        throw new RuntimeException('the mail server is down');
    }
}

/** The variables the deploy sets on an AWS queue function. */
const BOOTED_VARIABLES = [
    'SKE_ENVIRONMENT' => 'develop',
    'SKE_PROVIDER' => 'aws',
    'SQS_PREFIX' => 'https://sqs.eu-west-1.amazonaws.com/123456789012',
    'SQS_QUEUE' => 'default',
    'SQS_SUFFIX' => '--ske-shop-prod',
    'AWS_REGION' => 'eu-west-1',
];

/**
 * Runs a callback against a booted application on an AWS queue function,
 * restoring the environment, the container and facade globals afterwards and
 * removing the base path the framework wrote its manifests to.
 *
 * @param  Closure(Application): void  $callback
 */
function withBootedApplication(Closure $callback): void
{
    $server = $_SERVER;
    $container = Container::getInstance();
    $basePath = sys_get_temp_dir().'/ske-bridge-'.bin2hex(random_bytes(6));

    // Where the framework writes its provider and package manifests.
    mkdir($basePath.'/bootstrap/cache', 0777, true);

    foreach (BOOTED_VARIABLES as $key => $value) {
        $_SERVER[$key] = $value;
    }

    try {
        $app = Application::configure($basePath)->withExceptions()->create();
        Facade::setFacadeApplication($app);

        $app->bootstrapWith([LoadConfiguration::class, RegisterProviders::class, BootProviders::class]);
        $app->register(SkeServiceProvider::class);

        $callback($app);
    } finally {
        $_SERVER = $server;
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance($container);
        (new Filesystem)->deleteDirectory($basePath);
    }
}

/**
 * The body SqsQueue sends for a job, built by the framework's own payload code.
 */
function sqsBody(Application $app, object $job): string
{
    $queue = new class extends NullQueue
    {
        public function body(object $job): string
        {
            return $this->createPayload($job, 'emails');
        }
    };
    $queue->setContainer($app);

    return $queue->body($job);
}

it('runs a job delivered from SQS in a booted application', function () {
    FlagJob::$ran = false;

    withBootedApplication(function (Application $app) {
        $outcome = $app->make(QueueRunner::class)->process([[
            'message_id' => 'a1b2c3',
            'body' => sqsBody($app, new FlagJob),
            'queue' => 'emails--ske-shop-prod',
            'attempts' => 1,
        ]])[0];

        expect($outcome->error)->toBeNull()
            ->and(FlagJob::$ran)->toBeTrue()
            ->and($outcome->deleted)->toBeTrue()
            ->and($outcome->released || $outcome->failed)->toBeFalse();
    });
});

it('reports a job\'s exception through the application\'s handler, once', function () {
    withBootedApplication(function (Application $app) {
        // The bridge's stderr channel, written to a file the test can read.
        $log = $app->basePath('stderr.log');
        config(['logging.channels.stderr.with.stream' => $log]);

        // Counts what reaches the handler; it still logs each one itself.
        $reported = [];
        $handler = $app->make(ExceptionHandler::class);
        if ($handler instanceof Handler) {
            $handler->reportable(function (Throwable $e) use (&$reported) {
                $reported[] = $e->getMessage();
            });
        }

        $outcome = $app->make(QueueRunner::class)->process([[
            'message_id' => 'a1b2c3',
            'body' => sqsBody($app, new ThrowingJob),
            'queue' => 'emails--ske-shop-prod',
            'attempts' => 1,
        ]])[0];

        $records = array_map(
            fn (string $line) => json_decode($line, true),
            file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
        );

        expect($outcome->released)->toBeTrue()
            ->and($outcome->error)->toBe('the mail server is down')
            ->and($reported)->toBe(['the mail server is down'])
            ->and($records)->toHaveCount(1)
            ->and($records[0]['level_name'])->toBe('ERROR')
            ->and($records[0]['message'])->toBe('the mail server is down')
            ->and($records[0]['context']['exception']['class'])->toBe(RuntimeException::class);
    });
});
