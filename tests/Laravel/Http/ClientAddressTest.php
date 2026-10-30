<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bridge trusts no proxy: the platform hands PHP the address that connected
 * to the platform as REMOTE_ADDR, with HTTPS and the real host, as nginx does
 * on a server. Proxy trust is the application's own Laravel configuration,
 * run here through the framework's TrustProxies middleware as it would be in
 * the application's global middleware.
 *
 * Trusted proxies are static state on the Request class and the middleware,
 * so a test that sets them leaks into every test that follows. Reset before
 * and after.
 */
beforeEach(function (): void {
    Request::setTrustedProxies([], 0);
    TrustProxies::flushState();
});

afterEach(function (): void {
    Request::setTrustedProxies([], 0);
    TrustProxies::flushState();
});

/**
 * A request as the platform hands it to PHP: HTTPS on the real host, from the
 * address the platform saw connect.
 *
 * @param  array<string, string>  $headers
 */
function fromPlatform(string $source, array $headers = []): Request
{
    $request = Request::create('https://app.example.com/orders', 'GET');
    $request->server->set('REMOTE_ADDR', $source);

    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return $request;
}

/**
 * Runs the request through TrustProxies in an application with no
 * trustedproxy config, the middleware's fallback when no proxy is set.
 */
function throughTrustProxies(Request $request): Request
{
    $previous = Container::getInstance();
    $container = new Container;
    $container->instance('config', new Repository);
    Container::setInstance($container);

    try {
        (new TrustProxies)->handle($request, fn (): Response => new Response);
    } finally {
        Container::setInstance($previous);
    }

    return $request;
}

it('ignores forwarded headers when the application trusts no proxy', function () {
    // A client that sends its own X-Forwarded-* headers must not choose its
    // address (and so its rate-limit bucket) or the host in generated URLs.
    $request = throughTrustProxies(fromPlatform('203.0.113.9', [
        'X-Forwarded-For' => '198.51.100.66, 203.0.113.9',
        'X-Forwarded-Host' => 'evil.example.net',
    ]));

    expect($request->ip())->toBe('203.0.113.9')
        ->and($request->isSecure())->toBeTrue()
        ->and($request->getSchemeAndHttpHost())->toBe('https://app.example.com')
        ->and($request->fullUrl())->toBe('https://app.example.com/orders');
});

it('reads the visitor from a proxy the application trusts', function () {
    // Behind a CDN the platform sees the CDN's address. The application trusts
    // the CDN's ranges with trustProxies(at: [...]) in bootstrap/app.php, which
    // sets TrustProxies::at().
    TrustProxies::at(['192.0.2.0/24']);

    $request = throughTrustProxies(fromPlatform('192.0.2.10', [
        'X-Forwarded-For' => '203.0.113.9, 192.0.2.10',
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'www.example.com',
        'X-Forwarded-Port' => '443',
    ]));

    expect($request->ip())->toBe('203.0.113.9')
        ->and($request->getSchemeAndHttpHost())->toBe('https://www.example.com');
});

it('ignores forwarded headers from an address outside the trusted ranges', function () {
    TrustProxies::at(['192.0.2.0/24']);

    $request = throughTrustProxies(fromPlatform('203.0.113.9', [
        'X-Forwarded-For' => '198.51.100.66',
    ]));

    expect($request->ip())->toBe('203.0.113.9');
});
