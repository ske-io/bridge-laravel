<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Queue;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use Ske\Bridge\Queue\JobOutcome;
use Throwable;

/**
 * One queue message delivered by the platform rather than fetched from a broker.
 *
 * The platform holds the broker connection and the message's receipt handle; the
 * application never sees either. So this records what the worker decided —
 * deleted, released, failed — instead of acting on the queue itself, and the
 * platform carries the decision out.
 *
 * Failing goes through Laravel's own path: the job's failed() hook, the
 * JobFailed event, and (in QueueRunner) the failed_jobs record. A failed job is
 * finished: the platform acknowledges it, or keeps it as a dead letter when no
 * failed_jobs record holds it.
 */
final class PlatformJob extends Job implements JobContract
{
    private ?int $releaseDelay = null;

    private ?Throwable $failure = null;

    private ?string $error = null;

    private bool $requeued = false;

    private bool $deadLetter = false;

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

    /**
     * The platform's delivery count for this message, plus the attempts made
     * before it was put back as a new message (see QueueRunner), which the
     * payload carries the way Laravel's Redis queue does.
     */
    public function attempts(): int
    {
        $decoded = json_decode($this->payload, true);
        $earlier = is_array($decoded) && is_int($decoded['attempts'] ?? null) ? $decoded['attempts'] : 0;

        return $this->attempts + $earlier;
    }

    /**
     * Laravel's failure handling, keeping the exception for the failed_jobs
     * record and its message for the platform's log.
     *
     * @param  Throwable|null  $e
     */
    public function fail($e = null): void
    {
        if ($e instanceof Throwable) {
            $this->failure = $e;
            $this->error = $e->getMessage();
        }

        parent::fail($e);
    }

    public function failure(): ?Throwable
    {
        return $this->failure;
    }

    /**
     * Keeps an error that did not fail the job — one the worker rethrew after
     * releasing it — for the platform's log.
     */
    public function recordError(Throwable $e): void
    {
        $this->error ??= $e->getMessage();
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
     * The released job was put back on its queue as a new message, so this one
     * is done with.
     */
    public function markRequeued(): void
    {
        $this->requeued = true;
    }

    /**
     * The failed job is not recorded in failed_jobs, so the platform must keep
     * the message for it to be inspected and retried.
     */
    public function markAsDeadLetter(): void
    {
        $this->deadLetter = true;
    }

    /**
     * The outcome the platform needs in order to settle this message: failed
     * (finished; kept as a dead letter when unrecorded), released (deliver
     * again) or deleted (done).
     */
    public function outcome(): JobOutcome
    {
        $failed = $this->hasFailed();
        $released = ! $failed && $this->isReleased() && ! $this->requeued;

        return new JobOutcome(
            messageId: $this->messageId,
            deleted: ! $failed && ! $released && ($this->isDeleted() || $this->requeued),
            released: $released,
            releaseDelay: $released ? $this->releaseDelay : null,
            failed: $failed,
            deadLetter: $failed && $this->deadLetter,
            attempts: $this->attempts(),
            error: $this->error,
        );
    }
}
