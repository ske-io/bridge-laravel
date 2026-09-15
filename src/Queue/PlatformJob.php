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

    private ?string $error = null;

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

    /**
     * The parent's fail() dispatches events, calls the job class's failed()
     * hook, then deletes the job. None of that is appropriate here:
     *
     *  - Event dispatch needs a booted application, and this runs inside a
     *    catch block where the worker already reported the failure.
     *  - Deletion would mark the outcome as "deleted", telling the adapter to
     *    acknowledge the message — the opposite of what a failure should do.
     *
     * Recording the failure and its message is all the adapter needs.
     */
    public function fail($e = null): void
    {
        $this->markAsFailed();

        if ($e instanceof \Throwable) {
            $this->error = $e->getMessage();
        }
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
            error: $this->error,
        );
    }
}
