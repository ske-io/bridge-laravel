<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Http;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Teaches Laravel that the adapter in front of it is a proxy.
 *
 * Every request reaches the application through the platform's load balancer
 * and then the adapter, which forwards the real scheme and client address. Without
 * this the framework ignores both: url() generates http:// links behind TLS,
 * Request::isSecure() is false, secure cookies are not set, and Request::ip()
 * returns the adapter — so the throttle middleware rate-limits every visitor as
 * though they were one client.
 *
 * The proxy is the platform's own network hop rather than a host the deployment
 * can name, so every forwarding header is trusted. That is only safe because
 * nothing else can reach the application: it listens on loopback and the adapter
 * is the sole caller.
 */
final class TrustPlatformProxy extends TrustProxies
{
    /** @var string|array<int, string>|null */
    protected $proxies = '*';

    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_AWS_ELB;
}
