<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Facade;

afterEach(function (): void {
    Queue::createPayloadUsing(null);
    restore_error_handler();
    restore_exception_handler();
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
    Container::setInstance(null);
    unset($_SERVER['APP_BASE_PATH'], $_ENV['APP_RUNNING_IN_CONSOLE']);
});

require_once __DIR__.'/../Fixtures/octane.php';

it('shows validation errors once and isolates browser sessions across warm requests', function (string $session) {
    [$directory, $worker] = octaneApplication($session);

    try {
        $invalid = $worker->handle(octaneRequest('/form', 'POST', 'email=invalid', headers: ['Content-Type' => ['application/x-www-form-urlencoded']]));
        expect($invalid['status'])->toBe(302);
        $cookies = octaneCookies($invalid);
        $other = $worker->handle(octaneRequest('/form'));
        expect(json_decode(base64_decode($other['body']), true)['errors'])->toBeFalse();
        $redirect = $worker->handle(octaneRequest('/form', cookies: $cookies));
        expect(json_decode(base64_decode($redirect['body']), true)['errors'])->toBeTrue();
        $consumed = $worker->handle(octaneRequest('/form', cookies: octaneCookies($redirect, $cookies)));
        expect(json_decode(base64_decode($consumed['body']), true)['errors'])->toBeFalse();
    } finally {
        $worker->shutdown();
        (new Filesystem)->deleteDirectory($directory);
    }
})->with(['cookie', 'file']);

it('does not reuse authentication or request headers for the next visitor', function () {
    [$directory, $worker] = octaneApplication();

    try {
        $first = $worker->handle(octaneRequest('/identity', headers: ['X-User' => ['alice'], 'X-Request-Id' => ['request-one']]));
        $next = $worker->handle(octaneRequest('/identity', headers: ['X-Request-Id' => ['request-two']]));

        expect(json_decode(base64_decode($first['body']), true))->toBe(['user' => 'alice', 'request_id' => 'request-one']);
        expect(json_decode(base64_decode($next['body']), true))->toBe(['user' => null, 'request_id' => 'request-two']);
    } finally {
        $worker->shutdown();
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('runs finite stream callbacks inside their request context', function () {
    [$directory, $worker] = octaneApplication();

    try {
        $response = $worker->handle(octaneRequest('/stream', headers: ['X-Value' => ['current']]));

        expect(base64_decode($response['body']))->toBe('current:stream');
    } finally {
        $worker->shutdown();
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('preserves HTTP Basic authentication and native server headers', function () {
    [$directory, $worker] = octaneApplication();
    $authorization = 'Basic '.base64_encode('alice:secret');

    try {
        $response = $worker->handle(octaneRequest('/basic-auth', headers: ['Authorization' => [$authorization]]));

        expect(json_decode(base64_decode($response['body']), true))->toBe([
            'user' => 'alice', 'password' => 'secret', 'authorization' => $authorization,
        ]);
    } finally {
        $worker->shutdown();
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('serves a request that carries no host, such as a warm-up ping', function () {
    [$directory, $worker] = octaneApplication();

    try {
        $response = $worker->handle([...octaneRequest('/origin'), 'host' => '']);
        $next = $worker->handle(octaneRequest('/origin'));

        expect($response)->toMatchArray(['status' => 200, 'retire' => false]);
        expect(json_decode(base64_decode($response['body']), true))
            ->toBe(['host' => 'localhost', 'scheme' => 'https', 'port' => 443, 'path' => 'origin']);
        expect(json_decode(base64_decode($next['body']), true))
            ->toBe(['host' => 'example.test', 'scheme' => 'https', 'port' => 443, 'path' => 'origin']);
    } finally {
        $worker->shutdown();
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('answers a host the request cannot be built from with 400 and stays in service', function (string $host) {
    [$directory, $worker] = octaneApplication();

    try {
        $invalid = $worker->handle([...octaneRequest('/origin'), 'host' => $host]);
        $next = $worker->handle(octaneRequest('/origin'));

        expect($invalid)->toMatchArray(['status' => 400, 'retire' => false]);
        expect($next)->toMatchArray(['status' => 200, 'retire' => false]);
    } finally {
        $worker->shutdown();
        (new Filesystem)->deleteDirectory($directory);
    }
})->with(['h:99999', 'evil.com/x?', 'evil.com#x', 'user@evil.com']);

it('discards a captured success when termination throws and refuses another request', function () {
    [$directory, $worker] = octaneApplication();

    try {
        $response = $worker->handle(octaneRequest('/terminate-error'));

        expect($response)->toBe(['version' => 1, 'error' => 'Octane request lifecycle failed.', 'retire' => true]);
        expect(fn () => $worker->handle(octaneRequest('/form')))->toThrow(RuntimeException::class, 'retired');
    } finally {
        $worker->shutdown();
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('returns the final response before retiring at the configured request limit', function () {
    [$directory, $worker] = octaneApplication(maxRequests: 2);

    try {
        $first = $worker->handle(octaneRequest('/form'));
        $last = $worker->handle(octaneRequest('/form'));

        expect($first['retire'])->toBeFalse();
        expect($last)->toMatchArray(['status' => 200, 'retire' => true]);
        expect(fn () => $worker->handle(octaneRequest('/form')))->toThrow(RuntimeException::class, 'retired');
    } finally {
        $worker->shutdown();
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('maps nested uploads and repeated form fields without a multipart dependency', function () {
    [$directory, $worker] = octaneApplication();
    $path = $directory.'/upload.tmp';
    file_put_contents($path, "file\0bytes");
    $request = octaneRequest('/upload', 'POST', headers: ['Content-Type' => ['multipart/form-data; boundary=test']]);
    $request['parts'] = [
        ['name' => 'tags[]', 'value' => base64_encode('first')],
        ['name' => 'documents[receipt]', 'filename' => 'receipt.bin', 'path' => $path, 'content_type' => 'application/octet-stream', 'size' => 10],
        ['name' => 'tags[]', 'value' => base64_encode('second')],
    ];

    try {
        $response = $worker->handle($request);

        expect(json_decode(base64_decode($response['body']), true))->toBe([
            'tags' => ['first', 'second'], 'valid' => true, 'name' => 'receipt.bin',
            'bytes' => base64_encode("file\0bytes"), 'native_name' => 'receipt.bin',
        ]);
    } finally {
        $worker->shutdown();
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('bounds streamed output and retires a worker whose output exceeds the limit', function () {
    [$directory, $worker] = octaneApplication();
    $request = octaneRequest('/large-stream');
    $request['max_response_body'] = 100;

    try {
        $response = $worker->handle($request);

        expect($response)->toBe(['version' => 1, 'error' => 'Octane request lifecycle failed.', 'retire' => true]);
    } finally {
        $worker->shutdown();
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('preserves range downloads and delete-after-send behavior', function () {
    [$directory, $worker] = octaneApplication();

    try {
        $response = $worker->handle(octaneRequest('/download', headers: ['Range' => ['bytes=2-5']]));

        expect($response['status'])->toBe(206);
        expect(base64_decode($response['body']))->toBe('2345');
        expect(file_exists($directory.'/download.bin'))->toBeFalse();
    } finally {
        $worker->shutdown();
        (new Filesystem)->deleteDirectory($directory);
    }
});
