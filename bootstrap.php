<?php

declare(strict_types=1);

use Ske\Bridge\Laravel\Runtime\Environment;
use Ske\Bridge\Laravel\Runtime\WritablePaths;
use Ske\Bridge\Laravel\SkeServiceProvider;

// Loaded through Composer's autoload.files, so this runs when the application
// requires vendor/autoload.php: before bootstrap/app.php, before the container,
// before PackageManifest discovers a single provider. That is the only moment
// early enough to create the writable directories a read-only serverless image
// (Lambda, Cloud Run) points Laravel at — see WritablePaths for why a service
// provider is always too late, and Environment::publish() for why $_ENV must be
// populated before Dotenv touches it.

// $_ENV must be populated on every request: php-fpm gives each request fresh
// superglobals, so a value copied on the previous request is gone.
if (SkeServiceProvider::runningOnPlatform()) {
    Environment::publish();
}

// The remaining work — stripping .env and creating writable directories — only
// matters on cold start. A static flag skips the redundant filesystem checks
// on every warm request.
static $skeColdStartDone = false;

if (! $skeColdStartDone) {
    if (SkeServiceProvider::runningOnPlatform()) {
        Environment::stripDotenv();
    }

    $skeUnwritablePaths = WritablePaths::boot();

    if ($skeUnwritablePaths !== []) {
        fwrite(STDERR, sprintf(
            "ske: could not create writable directories: %s\n",
            implode(', ', $skeUnwritablePaths),
        ));
    }

    unset($skeUnwritablePaths);
    $skeColdStartDone = true;
}
