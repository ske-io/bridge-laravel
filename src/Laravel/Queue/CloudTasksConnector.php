<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Queue;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\Connectors\ConnectorInterface;

/**
 * Builds the Cloud Tasks queue for a connection using the `ske-cloudtasks`
 * driver.
 */
final readonly class CloudTasksConnector implements ConnectorInterface
{
    public const string DRIVER = 'ske-cloudtasks';

    public function __construct(
        private HttpFactory $http,
    ) {}

    /**
     * @param  array{project?: string, location?: string, prefix?: string, queue?: string, after_commit?: bool}  $config
     */
    public function connect(array $config): CloudTasksQueue
    {
        return new CloudTasksQueue(
            $this->http,
            (string) ($config['project'] ?? ''),
            (string) ($config['location'] ?? ''),
            (string) ($config['prefix'] ?? ''),
            (string) ($config['queue'] ?? 'default'),
            (bool) ($config['after_commit'] ?? false),
        );
    }
}
