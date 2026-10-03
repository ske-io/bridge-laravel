<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Queue;

use Aws\Sqs\SqsClient;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\SqsConnector as LaravelSqsConnector;
use RuntimeException;

/**
 * Laravel's SQS connector for the `ske` connection on AWS, refusing with
 * instructions when the AWS SDK is not installed. The bridge does not require
 * the SDK: an application can pick another queue connection instead.
 */
final class SqsConnector extends LaravelSqsConnector
{
    public const string DRIVER = 'ske-sqs';

    public function __construct(
        private readonly ?bool $sdkInstalled = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): Queue
    {
        if (! ($this->sdkInstalled ?? class_exists(SqsClient::class))) {
            throw new RuntimeException(
                'SKE queues on AWS use SQS: run `composer require aws/aws-sdk-php`, '
                .'or set QUEUE_CONNECTION to another connection in Secrets. '
                .'Note: database jobs only run if your app runs queue:work itself (for example from the scheduler).',
            );
        }

        return parent::connect($config);
    }
}
