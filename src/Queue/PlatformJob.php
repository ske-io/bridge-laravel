<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Queue;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

/**
 * One queue message delivered by the platform rather than fetched from a broker.
 *
 * The adapter holds the broker connection and the message's receipt handle; the
 * application never sees either. So this records what the worker decided —
 * deleted, released, failed — instead of acting on the queue itself, and the
 * adapter carries the decision out.
 *
 * The previous bridge built a real SqsJob and filled its receipt handle with the
 * message id, which is not one. Deletion was rejected, so every job that
 * succeeded was redelivered until the queue gave up on it.
 */
final class PlatformJob extends Job implements JobContract
{
    private ?int $releaseDelay = null;

    public function __construct(
        Container $container,
        private readonly string $messageId,
        private readonly string $payload,
        string $connectionName,
        string $queue,
        private readonly int $attempts = 1,
    ) {
        $this->container = $container;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
    }

    public function getJobId(): string
    {
        return $this->messageId;
    }

    public function getRawBody(): string
    {
        return $this->payload;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function release($delay = 0): void
    {
        parent::release($delay);

        $this->releaseDelay = (int) $delay;
    }

    public function releaseDelay(): ?int
    {
        return $this->releaseDelay;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    /**
     * The outcome the adapter needs in order to settle this message.
     */
    public function outcome(): JobOutcome
    {
        return new JobOutcome(
            messageId: $this->messageId,
            deleted: $this->isDeleted() && ! $this->isReleased(),
            released: $this->isReleased(),
            releaseDelay: $this->releaseDelay,
            failed: $this->hasFailed(),
        );
    }
}
