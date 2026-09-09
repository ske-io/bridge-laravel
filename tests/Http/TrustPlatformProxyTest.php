<?php

declare(strict_types=1);

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Ske\Bridge\Laravel\Http\TrustPlatformProxy;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trusted proxies are static state on the Request class, so a test that sets
 * them leaks into every test that follows. Reset before and after.
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
 * A request as the adapter forwards it: it reached the application over
 * loopback, and the real connection is described by the headers.
 */
/**
 * @param  array<string, string>  $headers
 */
function forwarded(array $headers = []): Request
{
    $request = Request::create('http://127.0.0.1/orders', 'GET');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return $request;
}

function throughProxy(Request $request): Request
{
    (new TrustPlatformProxy)->handle($request, fn (): Response => new Response);

    return $request;
}

it('leaves the forwarded headers meaningless until the middleware runs', function () {
    // The "before" half of every assertion below: none of this is Laravel
    // behaviour a deployment gets for free.
    $request = forwarded(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'app.example.com']);

    expect($request->isSecure())->toBeFalse()
        ->and($request->getHost())->toBe('127.0.0.1');
});

it('reports the scheme the client actually used', function () {
    // The load balancer terminates TLS, so the application only ever sees a
    // plaintext hop. Without this, url() writes http:// into every generated
    // link and secure cookies are never set.
    $request = throughProxy(forwarded(['X-Forwarded-Proto' => 'https']));

    expect($request->isSecure())->toBeTrue()
        ->and($request->getScheme())->toBe('https');
});

it('reports the host the client asked for', function () {
    $request = throughProxy(forwarded([
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'app.example.com',
    ]));

    expect($request->getHost())->toBe('app.example.com')
        ->and($request->getSchemeAndHttpHost())->toBe('https://app.example.com');
});

it('reports the port the client connected to', function () {
    $request = throughProxy(forwarded([
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'app.example.com',
        'X-Forwarded-Port' => '443',
    ]));

    expect($request->getPort())->toBe(443);
});

it('reports the client address rather than the adapter', function () {
    // Every request arrives from loopback, so without this the throttle
    // middleware buckets the entire internet as one client and the first busy
    // visitor locks out everyone else.
    $request = throughProxy(forwarded(['X-Forwarded-For' => '203.0.113.9, 10.0.0.7']));

    expect($request->ip())->toBe('203.0.113.9');
});

it('does not trust a forwarded path prefix', function () {
    // Laravel's own TrustProxies trusts X-Forwarded-Prefix. The adapter never
    // sends it — it forwards proto, host, port and for, and nothing else — so
    // anything arriving under that name came from outside and would rewrite
    // every generated URL.
    $request = throughProxy(forwarded([
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Prefix' => '/injected',
    ]));

    expect($request->getBaseUrl())->toBe('')
        ->and($request->getUri())->toBe('https://127.0.0.1/orders');
});

it('hands the request on to the rest of the pipeline', function () {
    $response = (new TrustPlatformProxy)->handle(
        forwarded(),
        fn (): Response => new Response('reached'),
    );

    expect($response->getContent())->toBe('reached');
});
