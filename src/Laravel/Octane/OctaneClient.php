<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Octane;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\Contracts\StoppableClient;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\RequestContext;
use RuntimeException;
use Throwable;

final class OctaneClient implements StoppableClient
{
    public bool $stopped = false;

    /** @var array<string, mixed>|null */
    public ?array $response = null;

    public int $maxResponseBody = 10485760;

    public function reset(): void
    {
        $this->response = null;
    }

    /** @return array<string, mixed>|null */
    public function result(): ?array
    {
        return $this->response;
    }

    /** @return array{Request, RequestContext} */
    public function marshalRequest(RequestContext $context): array
    {
        return [FramedRequest::make($context->data), $context];
    }

    public function respond(RequestContext $context, OctaneResponse $response): void
    {
        $body = '';
        $overflow = false;
        $level = ob_get_level();

        if (str_starts_with(strtolower($response->response->headers->get('Content-Type') ?? ''), 'text/event-stream')) {
            throw new RuntimeException('Live event streams are not supported by this worker.');
        }

        ob_start(function (string $chunk) use (&$body, &$overflow): string {
            if ($overflow || strlen($body) + strlen($chunk) > $this->maxResponseBody) {
                $overflow = true;

                return '';
            }

            $body .= $chunk;

            return '';
        }, 8192);

        try {
            echo $response->outputBuffer;
            $response->response->sendContent();
            ob_end_flush();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        if ($overflow) {
            throw new RuntimeException('Response exceeds the platform body limit.');
        }

        $headers = $response->response->headers->all();

        if (($context->data['method'] ?? '') !== 'HEAD' && ! in_array($response->response->getStatusCode(), [204, 304], true)) {
            $headers['content-length'] = [(string) strlen($body)];
        }
        $this->response = [
            'version' => 1,
            'status' => $response->response->getStatusCode(),
            'headers' => $headers,
            'body' => base64_encode($body),
        ];
    }

    public function error(Throwable $e, Application $app, Request $request, RequestContext $context): void
    {
        $this->stop();
    }

    public function stop(): void
    {
        $this->stopped = true;
    }
}
