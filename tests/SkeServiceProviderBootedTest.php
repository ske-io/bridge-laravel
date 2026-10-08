<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
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

it('runs a job delivered from SQS in a booted application', function () {
    FlagJob::$ran = false;

    withBootedApplication(function (Application $app) {
        // The body SqsQueue sends, built by the framework's own payload code.
        $queue = new class extends NullQueue
        {
            public function body(object $job): string
            {
                return $this->createPayload($job, 'emails');
            }
        };
        $queue->setContainer($app);

        $outcome = $app->make(QueueRunner::class)->process([[
            'message_id' => 'a1b2c3',
            'body' => $queue->body(new FlagJob),
            'queue' => 'emails--ske-shop-prod',
            'attempts' => 1,
        ]])[0];

        expect($outcome->error)->toBeNull()
            ->and(FlagJob::$ran)->toBeTrue()
            ->and($outcome->deleted)->toBeTrue()
            ->and($outcome->released || $outcome->failed)->toBeFalse();
    });
});
