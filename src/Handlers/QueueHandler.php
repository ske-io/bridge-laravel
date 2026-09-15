<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Handlers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ske\Bridge\Laravel\Queue\JobOutcome;
use Ske\Bridge\Laravel\Queue\QueueRunner;

final readonly class QueueHandler
{
    public function __construct(
        private QueueRunner $runner,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        /** @var list<array{message_id?: string, body?: string, attempts?: int}> $records */
        $records = is_array($payload['records'] ?? null) ? $payload['records'] : [];

        /** @var array{timeout?: int, memory?: int, tries?: int, backoff?: int} $options */
        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];

        $outcomes = $this->runner->process($records, $options);

        return new JsonResponse([
            'processed' => count($outcomes),
            'outcomes' => array_map(fn (JobOutcome $o): array => $o->toArray(), $outcomes),
        ]);
    }
}
