<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Fixtures/octane.php';

/** @param resource $connection */
function workerBytes($connection, int $length): string
{
    $result = '';

    while (strlen($result) < $length) {
        $bytes = fread($connection, max(1, $length - strlen($result)));

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Worker connection closed before the response completed.');
        }

        $result .= $bytes;
    }

    return $result;
}

/** @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function workerExchange(string $socket, array $request): array
{
    $connection = stream_socket_client('unix://'.$socket, $error, $message, 5);

    if ($connection === false) {
        throw new RuntimeException($message);
    }

    stream_set_timeout($connection, 5);

    try {
        $json = json_encode($request, JSON_THROW_ON_ERROR);
        fwrite($connection, pack('N', strlen($json)).$json);
        $header = unpack('Nlength', workerBytes($connection, 4));

        if ($header === false) {
            throw new RuntimeException('Invalid response prefix.');
        }

        return json_decode(workerBytes($connection, $header['length']), true, 512, JSON_THROW_ON_ERROR);
    } finally {
        fclose($connection);
    }
}

it('serves isolated warm requests over the real worker socket and exits after its final response', function () {
    $directory = octaneApplicationDirectory();
    $socket = $directory.'/worker.sock';
    symlink(dirname(__DIR__, 2).'/vendor', $directory.'/vendor');
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/bin/octane-worker'], $directory, [
        'SKE_PROVIDER' => 'aws', 'SKE_WORKER_SOCKET' => $socket, 'SKE_WORKER_MAX_REQUESTS' => '3',
        'SKE_PROJECT_TYPE' => 'laravel', 'SKE_ENVIRONMENT' => 'testing',
    ]);

    try {
        $process->start();
        $deadline = microtime(true) + 5;

        while (! file_exists($socket)) {
            if (! $process->isRunning() || microtime(true) >= $deadline) {
                throw new RuntimeException('Worker failed to boot: '.$process->getErrorOutput());
            }

            usleep(10000);
        }

        // A readiness connection must not consume a request or retire the worker.
        $probe = stream_socket_client('unix://'.$socket);
        if ($probe === false) {
            throw new RuntimeException('Readiness connection failed.');
        }
        fclose($probe);
        $invalid = workerExchange($socket, octaneRequest('/form', 'POST', 'email=invalid', headers: ['Content-Type' => ['application/x-www-form-urlencoded']]));
        $redirect = workerExchange($socket, octaneRequest('/form', cookies: octaneCookies($invalid)));
        $other = workerExchange($socket, octaneRequest('/form'));
        $process->wait();

        expect($invalid['status'])->toBe(302);
        expect(json_decode(base64_decode($redirect['body']), true)['errors'])->toBeTrue();
        expect(json_decode(base64_decode($other['body']), true)['errors'])->toBeFalse();
        expect($other['retire'])->toBeTrue();
        expect($process->getExitCode())->toBe(0);
        expect($process->getOutput())->toBe('');
        expect(file_exists($socket))->toBeFalse();
    } finally {
        $process->stop(1);
        unlink($directory.'/vendor');
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('recovers a socket left in tmp by a killed worker', function () {
    $directory = octaneApplicationDirectory();
    $socket = $directory.'/worker.sock';
    symlink(dirname(__DIR__, 2).'/vendor', $directory.'/vendor');
    $old = stream_socket_server('unix://'.$socket);

    if ($old === false) {
        throw new RuntimeException('Cannot create stale socket fixture.');
    }

    fclose($old);
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/bin/octane-worker'], $directory, [
        'SKE_PROVIDER' => 'aws', 'SKE_WORKER_SOCKET' => $socket, 'SKE_WORKER_MAX_REQUESTS' => '1',
        'SKE_PROJECT_TYPE' => 'laravel', 'SKE_ENVIRONMENT' => 'testing',
    ]);

    try {
        $process->start();
        $deadline = microtime(true) + 5;

        do {
            set_error_handler(static fn (int $level, string $message): bool => $level === E_WARNING && str_contains($message, 'Connection refused'));

            try {
                $probe = stream_socket_client('unix://'.$socket, $code, $message, 0.1);
            } finally {
                restore_error_handler();
            }

            if (is_resource($probe)) {
                fclose($probe);
                break;
            }

            if (! $process->isRunning() || microtime(true) >= $deadline) {
                throw new RuntimeException('Worker did not reclaim its socket: '.$process->getErrorOutput());
            }

            usleep(10000);
        } while (true);

        $response = workerExchange($socket, octaneRequest('/form'));
        $process->wait();

        expect($response['status'])->toBe(200);
        expect($process->getExitCode())->toBe(0);
    } finally {
        $process->stop(1);
        unlink($directory.'/vendor');
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('withholds the socket response until termination finishes and is safe to freeze immediately afterward', function () {
    $directory = octaneApplicationDirectory();
    $socket = $directory.'/worker.sock';
    symlink(dirname(__DIR__, 2).'/vendor', $directory.'/vendor');
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/bin/octane-worker'], $directory, [
        'SKE_PROVIDER' => 'aws', 'SKE_WORKER_SOCKET' => $socket, 'SKE_WORKER_MAX_REQUESTS' => '250',
        'SKE_PROJECT_TYPE' => 'laravel', 'SKE_ENVIRONMENT' => 'testing',
    ]);
    $connection = null;
    $pid = null;

    try {
        $process->start();
        $deadline = microtime(true) + 5;

        while (! file_exists($socket)) {
            if (! $process->isRunning() || microtime(true) >= $deadline) {
                throw new RuntimeException('Worker failed to boot: '.$process->getErrorOutput());
            }

            usleep(10000);
        }

        $connection = stream_socket_client('unix://'.$socket);

        if ($connection === false) {
            throw new RuntimeException('Cannot connect to worker.');
        }

        stream_set_timeout($connection, 5);
        $json = json_encode(octaneRequest('/termination-barrier'), JSON_THROW_ON_ERROR);
        fwrite($connection, pack('N', strlen($json)).$json);

        while (! file_exists($directory.'/termination-started')) {
            if (! $process->isRunning() || microtime(true) >= $deadline) {
                throw new RuntimeException('Termination callback did not start.');
            }

            usleep(1000);
        }

        $read = [$connection];
        $write = $except = [];
        expect(stream_select($read, $write, $except, 0))->toBe(0);
        touch($directory.'/termination-release');
        $header = unpack('Nlength', workerBytes($connection, 4));

        if ($header === false) {
            throw new RuntimeException('Invalid response prefix.');
        }

        $response = json_decode(workerBytes($connection, $header['length']), true, 512, JSON_THROW_ON_ERROR);
        $pid = $process->getPid();

        if ($pid !== null && function_exists('posix_kill')) {
            posix_kill($pid, SIGSTOP);
        }

        expect(file_get_contents($directory.'/termination-done'))->toBe('done');
        expect(base64_decode($response['body']))->toBe('ready');
    } finally {
        if ($pid !== null && function_exists('posix_kill')) {
            posix_kill($pid, SIGCONT);
        }

        if (is_resource($connection)) {
            fclose($connection);
        }

        $process->stop(1);
        unlink($directory.'/vendor');
        (new Filesystem)->deleteDirectory($directory);
    }
});
