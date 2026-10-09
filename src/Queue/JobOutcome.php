<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Queue;

/**
 * What the adapter should do with one message now that the worker has run it.
 *
 * Deleting, releasing and failing all need the receipt handle, which only the
 * adapter has — so the application states the decision and the adapter performs
 * it.
 *
 * A failed job is finished. When the application kept no record of it (no
 * failed jobs store, or one that could not be written) it is a dead letter:
 * the adapter keeps the message where the platform keeps failed messages, so it
 * can still be inspected and retried.
 */
final readonly class JobOutcome
{
    public function __construct(
        public string $messageId,
        public bool $deleted,
        public bool $released,
        public ?int $releaseDelay,
        public bool $failed,
        public bool $deadLetter = false,
        public int $attempts = 0,
        public ?string $error = null,
    ) {}

    /**
     * @return array{message_id: string, deleted: bool, released: bool, release_delay: int|null, failed: bool, dead_letter: bool, attempts: int, error: string|null}
     */
    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId,
            'deleted' => $this->deleted,
            'released' => $this->released,
            'release_delay' => $this->releaseDelay,
            'failed' => $this->failed,
            'dead_letter' => $this->deadLetter,
            'attempts' => $this->attempts,
            'error' => $this->error,
        ];
    }
}
