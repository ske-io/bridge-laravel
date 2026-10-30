<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Octane;

use Composer\InstalledVersions;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Worker;
use RuntimeException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Throwable;

final class OctaneWorker
{
    private OctaneClient $client;

    private Worker $worker;

    private int $requests = 0;

    private bool $terminated = false;

    public function __construct(private readonly string $basePath, private readonly int $maxRequests = 250)
    {
        if ($maxRequests < 1) {
            throw new RuntimeException('The worker request limit must be positive.');
        }
    }

    public static function validateInstallation(): void
    {
        $version = InstalledVersions::isInstalled('laravel/octane') ? InstalledVersions::getVersion('laravel/octane') : null;

        if ($version === null || version_compare($version, '2.22.0', '<') || version_compare($version, '3.0.0', '>=')) {
            throw new RuntimeException('Octane mode requires laravel/octane ^2.22 in production Composer dependencies.');
        }

        if (PHP_VERSION_ID >= 80600) {
            throw new RuntimeException('Octane mode supports PHP 8.3–8.5.');
        }
    }

    public function boot(): void
    {
        self::validateInstallation();
        $_ENV['APP_RUNNING_IN_CONSOLE'] = false;
        $_SERVER['APP_BASE_PATH'] = $this->basePath;
        $this->client = new OctaneClient;
        $this->worker = new Worker(new ApplicationFactory($this->basePath), $this->client);
        $this->worker->boot();

        // Observe errors even when the application's published Octane config
        // omits StopWorkerIfNecessary. Octane can swallow termination errors.
        $this->worker->application()->make('events')->listen(WorkerErrorOccurred::class, function (): void {
            $this->client->stop();
        });
    }

    /** @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    public function handle(array $envelope): array
    {
        if ($this->client->stopped || $this->terminated) {
            throw new RuntimeException('The Octane worker has retired.');
        }

        $server = $_SERVER;
        $env = $_ENV;
        $level = ob_get_level();
        $this->client->reset();
        $this->client->maxResponseBody = max(1, (int) ($envelope['max_response_body'] ?? 10485760));

        try {
            try {
                [$request, $context] = $this->client->marshalRequest(new RequestContext($envelope));
            } catch (BadRequestException) {
                // A request the application never saw leaves nothing to retire for.
                return self::badRequest();
            }

            $_SERVER = array_replace($server, $request->server->all());
            $_GET = $request->query->all();
            $_POST = $request->request->all();
            $_COOKIE = $request->cookies->all();
            $_REQUEST = array_replace($_GET, $_POST);
            $_FILES = FramedRequest::nativeFiles($request);
            $this->worker->handle($request, $context);

            $response = $this->client->result();

            if ($this->client->stopped || $response === null) {
                throw new RuntimeException('Octane request termination or cleanup failed.');
            }

            if (++$this->requests >= $this->maxRequests) {
                $this->shutdown();
            }

            return [...$response, 'retire' => $this->terminated];
        } catch (Throwable $e) {
            // The response carries no detail, so this is the only record of
            // why the request failed before or after the application ran.
            error_log('Octane request lifecycle failed: '.$e::class.': '.$e->getMessage());
            $this->client->stop();

            try {
                $this->shutdown();
            } catch (Throwable) {
                // The adapter will terminate the entire process group.
            }

            return ['version' => 1, 'error' => 'Octane request lifecycle failed.', 'retire' => true];
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            $this->client->reset();
            $_SERVER = $server;
            $_ENV = $env;
            $_GET = $_POST = $_COOKIE = $_REQUEST = $_FILES = [];
        }
    }

    /** @return array<string, mixed> */
    private static function badRequest(): array
    {
        $body = "Bad Request\n";

        return ['version' => 1, 'status' => 400, 'headers' => [
            'content-type' => ['text/plain; charset=UTF-8'], 'content-length' => [(string) strlen($body)],
        ], 'body' => base64_encode($body), 'retire' => false];
    }

    public function shutdown(): void
    {
        if (! $this->terminated && isset($this->worker)) {
            $this->terminated = true;
            $this->worker->terminate();
        }
    }
}
