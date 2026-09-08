<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Queue;

/**
 * What the adapter should do with one message now that the worker has run it.
 *
 * Deleting, releasing and failing all need the receipt handle, which only the
 * adapter has — so the application states the decision and the adapter performs
 * it.
 */
final readonly class JobOutcome
{
    public function __construct(
        public string $messageId,
        public bool $deleted,
        public bool $released,
        public ?int $releaseDelay,
        public bool $failed,
    ) {}

    /**
     * @return array{message_id: string, deleted: bool, released: bool, release_delay: int|null, failed: bool}
     */
    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId,
            'deleted' => $this->deleted,
            'released' => $this->released,
            'release_delay' => $this->releaseDelay,
            'failed' => $this->failed,
        ];
    }
}
