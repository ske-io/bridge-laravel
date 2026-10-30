<?php

declare(strict_types=1);

use Ske\Bridge\Laravel\Octane\OctaneWorker;

function octaneApplicationDirectory(string $session = 'cookie'): string
{
    $directory = sys_get_temp_dir().'/ske-octane-'.bin2hex(random_bytes(6));
    mkdir($directory.'/bootstrap/cache', 0777, true);
    mkdir($directory.'/storage/framework/sessions', 0777, true);
    mkdir($directory.'/storage/framework/views', 0777, true);
    mkdir($directory.'/config', 0777, true);
    touch($directory.'/.env');
    file_put_contents($directory.'/bootstrap/app.php', '<?php return require '.var_export(__DIR__.'/octane-app.php', true).';');
    file_put_contents($directory.'/config/session.php', '<?php return '.var_export([
        'driver' => $session, 'files' => $directory.'/storage/framework/sessions', 'cookie' => 'ske_session',
        'lifetime' => 120, 'path' => '/', 'http_only' => true, 'same_site' => 'lax',
    ], true).';');

    return $directory;
}

/** @return array{0: string, 1: OctaneWorker} */
function octaneApplication(string $session = 'cookie', int $maxRequests = 250): array
{
    $directory = octaneApplicationDirectory($session);
    $worker = new OctaneWorker($directory, $maxRequests);
    $worker->boot();

    return [$directory, $worker];
}

/** @param array<string, string> $cookies
 * @param  array<string, list<string>>  $headers
 * @return array<string, mixed>
 */
function octaneRequest(string $uri, string $method = 'GET', string $body = '', array $cookies = [], array $headers = []): array
{
    $headers['Cookie'] = [implode('; ', array_map(fn ($name, $value) => $name.'='.rawurlencode($value), array_keys($cookies), $cookies))];

    return ['version' => 1, 'method' => $method, 'uri' => $uri, 'host' => 'example.test', 'scheme' => 'https',
        'remote_addr' => '127.0.0.1:12345', 'headers' => $headers, 'body' => base64_encode($body), 'max_response_body' => 1048576];
}

/** @param array<string, mixed> $response
 * @param  array<string, string>  $cookies
 * @return array<string, string>
 */
function octaneCookies(array $response, array $cookies = []): array
{
    foreach ($response['headers']['set-cookie'] ?? [] as $header) {
        [$pair] = explode(';', $header);
        [$name, $value] = explode('=', $pair, 2);
        $cookies[$name] = rawurldecode($value);
    }

    return $cookies;
}
