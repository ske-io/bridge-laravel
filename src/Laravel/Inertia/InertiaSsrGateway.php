<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Inertia;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Ssr\HttpGateway;
use Inertia\Ssr\Response;
use Inertia\Ssr\SsrRenderFailed;
use ReflectionClass;
use ReflectionMethod;
use Ske\Bridge\Runtime\Environment;
use Throwable;

/**
 * Loaded only when the application installs Inertia. Inheriting its gateway
 * preserves Inertia 3's conditional SSR and excluded-path contracts, while
 * replacing the production transport shared by Inertia 2 and 3.
 */
final class InertiaSsrGateway extends HttpGateway
{
    /** @param array<string, mixed> $page */
    public function dispatch(array $page, ?Request $request = null): ?Response
    {
        // Inertia 2 has no request parameter; Inertia 3 uses it for excluded paths.
        $enabled = new ReflectionMethod(HttpGateway::class, 'ssrIsEnabled');
        $arguments = $enabled->getNumberOfParameters() > 0
            ? [$request ?? request()]
            : [];

        if ($enabled->invoke($this, ...$arguments) !== true) {
            return null;
        }

        if (config('inertia.ssr.ensure_bundle_exists', true) && ! $this->bundleExists()) {
            $this->logFallback($page, 'SSR bundle is missing.');

            return null;
        }

        try {
            $response = $this->ssrRequest()->timeout($this->timeout())
                ->connectTimeout($this->timeout())
                ->post($this->rendererUrl('/render'), $page);

            if ($response->failed()) {
                $this->logFallback($page, 'SSR renderer returned HTTP '.$response->status().'.');

                return null;
            }

            $data = $response->json();

            if (! is_array($data) || ! is_array($data['head'] ?? null) || ! is_string($data['body'] ?? null)
                || array_filter($data['head'], fn (mixed $entry): bool => ! is_string($entry)) !== []) {
                $this->logFallback($page, 'SSR renderer returned an invalid response.');

                return null;
            }

            return new Response(implode("\n", $data['head']), $data['body']);
        } catch (Throwable $exception) {
            if ($exception instanceof StrayRequestException) {
                throw $exception;
            }

            $this->logFallback($page, $exception->getMessage());

            return null;
        }
    }

    public function isHealthy(): bool
    {
        try {
            return Http::timeout($this->timeout())
                ->connectTimeout($this->timeout())
                ->get($this->rendererUrl('/health'))
                ->successful();
        } catch (Throwable $exception) {
            if ($exception instanceof StrayRequestException) {
                throw $exception;
            }

            return false;
        }
    }

    private function timeout(): float
    {
        $timeout = (float) (Environment::get('SKE_SSR_TIMEOUT') ?? 1);

        return $timeout > 0 ? $timeout : 1;
    }

    private function rendererUrl(string $path): string
    {
        return rtrim((string) config('inertia.ssr.url', 'http://127.0.0.1:13714'), '/').$path;
    }

    private function ssrRequest(): PendingRequest
    {
        $gateway = new ReflectionClass(HttpGateway::class);

        return $gateway->hasMethod('pendingRequest')
            ? $gateway->getMethod('pendingRequest')->invoke($this)
            : Http::createPendingRequest();
    }

    /** @param array<string, mixed> $page */
    private function logFallback(array $page, string $reason): void
    {
        Log::error('Inertia SSR failed; using client-side rendering.', [
            'component' => $page['component'] ?? null,
            'url' => $page['url'] ?? null,
            'reason' => $reason,
        ]);

        if (class_exists(SsrRenderFailed::class)) {
            SsrRenderFailed::dispatch($page, $reason);
        }
    }
}
