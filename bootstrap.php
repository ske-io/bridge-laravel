<?php

declare(strict_types=1);

use Ske\Bridge\Laravel\Runtime\Environment;
use Ske\Bridge\Laravel\SkeServiceProvider;

// Loaded through Composer's autoload.files, so this runs when the application
// requires vendor/autoload.php: before bootstrap/app.php, before the container,
// before PackageManifest discovers a single provider.

if (! SkeServiceProvider::runningOnPlatform()) {
    return;
}

// $_ENV must be populated on every request: php-fpm gives each request fresh
// superglobals, so a value copied on the previous request is gone.
Environment::publish();

// Stripping .env only matters on cold start — the file is already gone after
// the first request. A static flag skips the redundant filesystem check.
static $skeColdStartDone = false;

if (! $skeColdStartDone) {
    Environment::stripDotenv();
    $skeColdStartDone = true;
}
