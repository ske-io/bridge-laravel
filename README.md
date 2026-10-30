# SKE Laravel bridge

`ske-io/laravel-bridge` runs a Laravel application on [SKE](https://ske.io), which deploys it into your own AWS or GCP account. It connects the application's HTTP requests, queued jobs, commands and scheduler to the platform.

## Requirements

- PHP 8.3 or later
- Laravel 11, 12 or 13

## Installation

```bash
composer require ske-io/laravel-bridge
```

Laravel discovers the service provider, so there is nothing to register or configure. Off the platform the bridge stays inactive, apart from the [upload endpoint](https://ske.io/docs/laravel/uploads), so the same application runs locally as before.

On AWS, the platform's queue connection sends jobs to SQS through the AWS SDK, which the bridge doesn't require. Install it if the application doesn't have it yet:

```bash
composer require aws/aws-sdk-php
```

## What it does on the platform

- **Queues:** adds the `ske` queue connection (SQS on AWS, Cloud Tasks on GCP) and runs the jobs the platform delivers, recording failures in the application's failed jobs store.
- **Logs:** writes logs to stderr, one JSON line per record, so the platform's log viewer shows level, message, context and exception.
- **AWS credentials:** lets the AWS SDK use the function role's temporary credentials. A key pair the application sets itself still wins.
- **Mail:** sends SES mail from the region of the mail provider linked to the environment.
- **Maintenance mode:** follows the maintenance mode set in the SKE dashboard. See [Maintenance mode](https://ske.io/docs/deployments/maintenance).
- **Encrypted environment file:** loads the environment's `.env.{environment}.encrypted` when its key is set. See [Encrypted environment file](https://ske.io/docs/laravel/encrypted-environment).
- **Inertia SSR:** when SSR is turned on for the environment, sends Inertia's render calls to the platform's SSR server. This needs `inertiajs/inertia-laravel`.

## Client address and proxies

The bridge trusts no proxy. On the platform the request reaches Laravel over HTTPS on its real host, from the address that connected to the platform, so `Request::ip()` and `url()` are right with no configuration, as behind nginx on a server. Behind a CDN such as Cloudflare, that address is the CDN's: trust the CDN's published ranges with `$middleware->trustProxies(at: [...])` in `bootstrap/app.php`, never `*`. See [Behind a CDN or proxy](https://ske.io/docs/laravel#behind-a-cdn-or-proxy).

## Laravel Octane

Octane is an optional HTTP execution mode on AWS Lambda. Install it as a production dependency:

```bash
composer require 'laravel/octane:^2.22'
ske settings runtime octane=true
ske deploy
```

This mode supports Laravel 11–13 and PHP 8.3–8.5. Laravel stays booted between HTTP requests. Queues, scheduled tasks, and Artisan commands keep their existing execution paths. PHP-FPM remains the default when Octane is disabled.

The bridge captures each response, runs termination callbacks and Octane cleanup, and only then returns the response to the platform. A lifecycle failure fails the invocation and discards the worker; the platform does not replay the request. Application side effects may already have occurred. Workers also retire after 250 requests by default; set a positive `octane-max-requests` runtime setting to change the limit.

Forms, sessions, cookies, nested uploads, and downloads retain their Laravel behavior. Finite streamed responses are buffered while their request context is active. Platform body limits and deadlines apply; live streaming and SSE are unavailable. The platform parses multipart requests and owns temporary upload files until the request lifecycle completes. No multipart-parser package is required in the application.

Applications must follow Laravel's Octane compatibility guidance: do not retain request-specific state in static variables or long-lived singletons. This mode does not run Swoole, RoadRunner, or FrankenPHP servers. Server-specific task workers, shared tables, timers, and Octane server management commands are unavailable; use queues and scheduled tasks for durable background work.

Cloud Run is not supported by this mode yet.

## Documentation

- [Laravel on SKE](https://ske.io/docs/laravel)
- [Direct uploads](https://ske.io/docs/laravel/uploads)
- [Encrypted environment file](https://ske.io/docs/laravel/encrypted-environment)
- [Maintenance mode](https://ske.io/docs/deployments/maintenance)

## Development

```bash
composer install
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/pest
```

## License

The MIT License. See [LICENSE](LICENSE).
