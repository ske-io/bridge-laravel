<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Ske\Bridge\Laravel\Http\Middleware\CorrelateRequests;
use Symfony\Component\HttpFoundation\Response;

final class RecordingLogManager extends LogManager
{
    /** @var array<string, mixed>|null */
    public ?array $lastContext = null;

    public function __construct()
    {
        // No parent — the real LogManager needs an Application.
    }

    /** @param array<string, mixed> $context */
    public function withContext(array $context = []): static
    {
        $this->lastContext = $context;

        return $this;
    }
}

function requestWithCorrelationId(?string $value): Request
{
    $request = Request::create('/', 'GET');

    if ($value !== null) {
        $request->headers->set(CorrelateRequests::HEADER, $value);
    }

    return $request;
}

it('sets the request ID in the log context', function () {
    $log = new RecordingLogManager;
    $middleware = new CorrelateRequests($log);

    $middleware->handle(requestWithCorrelationId('abc-123'), fn (): Response => new Response);

    expect($log->lastContext)->toBe(['request_id' => 'abc-123']);
});

it('does not set context when the header is absent', function () {
    $log = new RecordingLogManager;
    $middleware = new CorrelateRequests($log);

    $middleware->handle(requestWithCorrelationId(null), fn (): Response => new Response);

    expect($log->lastContext)->toBeNull();
});

it('does not set context when the header is empty', function () {
    $log = new RecordingLogManager;
    $middleware = new CorrelateRequests($log);

    $middleware->handle(requestWithCorrelationId(''), fn (): Response => new Response);

    expect($log->lastContext)->toBeNull();
});

it('passes the request through to the next handler', function () {
    $middleware = new CorrelateRequests(new RecordingLogManager);

    $response = $middleware->handle(
        requestWithCorrelationId('test'),
        fn (): Response => new Response('reached'),
    );

    expect($response->getContent())->toBe('reached');
});
