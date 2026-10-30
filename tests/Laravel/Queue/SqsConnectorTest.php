<?php

declare(strict_types=1);

use Aws\Sqs\SqsClient;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Queue\QueueManager;
use Ske\Bridge\Laravel\Queue\SqsConnector;

const SQS_CONFIG = [
    'driver' => SqsConnector::DRIVER,
    'prefix' => 'https://sqs.eu-west-1.amazonaws.com/123456789012',
    'queue' => 'default',
    'suffix' => '--ske-shop-prod',
    'region' => 'eu-west-1',
];

it('tells the developer what to do when the AWS SDK is missing', function () {
    expect(fn () => (new SqsConnector(sdkInstalled: false))->connect(SQS_CONFIG))
        ->toThrow(RuntimeException::class, 'SKE queues on AWS use SQS: run `composer require aws/aws-sdk-php`, or set QUEUE_CONNECTION to another connection in Secrets.');
});

it('refuses at connect time, when the ske connection is first used', function () {
    // This package's own dependencies leave the SDK out, as an application
    // that never asked for it does.
    expect(class_exists(SqsClient::class))->toBeFalse();

    $previous = Container::getInstance();
    $app = new Application(sys_get_temp_dir());
    Container::setInstance($previous);
    $app->instance('config', new Repository(['queue' => ['connections' => ['ske' => SQS_CONFIG]]]));
    $manager = new QueueManager($app);
    $manager->addConnector(SqsConnector::DRIVER, fn () => new SqsConnector);

    expect(fn () => $manager->connection('ske'))
        ->toThrow(RuntimeException::class, 'database jobs only run if your app runs queue:work itself');
});
