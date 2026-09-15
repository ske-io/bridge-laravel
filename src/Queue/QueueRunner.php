<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Queue;

use Illuminate\Container\Container;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Throwable;

/**
 * Runs the messages the platform delivered in one invocation.
 *
 * Options come from the deployment's queue configuration rather than the
 * framework's defaults: `ske.yml` defines timeout, memory and tries, the API
 * validates them and the adapter carries them, and before this they reached the
 * worker as nothing at all.
 */
final readonly class QueueRunner
{
    public function __construct(
        private Worker $worker,
        private Container $container,
        private string $connectionName = 'ske',
        private string $queue = 'default',
    ) {}

    /**
     * @param  list<array{message_id?: string, body?: string, attempts?: int}>  $records
     * @param  array{timeout?: int, memory?: int, tries?: int, backoff?: int}  $options
     * @return list<JobOutcome> one per record, in the order they arrived
     */
    public function process(array $records, array $options = []): array
    {
        $workerOptions = $this->workerOptions($options);
        $outcomes = [];

        foreach ($records as $record) {
            $outcomes[] = $this->processOne($record, $workerOptions);
        }

        return $outcomes;
    }

    /**
     * @param  array{message_id?: string, body?: string, attempts?: int}  $record
     */
    private function processOne(array $record, WorkerOptions $options): JobOutcome
    {
        $job = new PlatformJob(
            container: $this->container,
            messageId: (string) ($record['message_id'] ?? ''),
            payload: (string) ($record['body'] ?? '{}'),
            connectionName: $this->connectionName,
            queue: $this->queue,
            attempts: (int) ($record['attempts'] ?? 1),
        );

        try {
            $this->worker->process($this->connectionName, $job, $options);
        } catch (Throwable $e) {
            $job->fail($e);
        }

        return $job->outcome();
    }

    /**
     * @param  array{timeout?: int, memory?: int, tries?: int, backoff?: int}  $options
     */
    private function workerOptions(array $options): WorkerOptions
    {
        $workerOptions = new WorkerOptions;

        $workerOptions->timeout = $options['timeout'] ?? $workerOptions->timeout;
        $workerOptions->memory = $options['memory'] ?? $workerOptions->memory;
        $workerOptions->maxTries = $options['tries'] ?? $workerOptions->maxTries;
        $workerOptions->backoff = $options['backoff'] ?? $workerOptions->backoff;

        // The platform invokes once per delivery, so the worker must never
        // block waiting for more work or sleep between messages.
        $workerOptions->sleep = 0;
        $workerOptions->stopWhenEmpty = true;

        return $workerOptions;
    }
}
