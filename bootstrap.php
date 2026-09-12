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

// On-platform: copy the process environment into $_ENV so that Laravel's env()
// and Dotenv's immutability check see the platform-set values, regardless of
// php.ini's variables_order setting. Then remove any .env file that made it
// into the image, so Dotenv cannot load stale values at all.
if (SkeServiceProvider::runningOnPlatform()) {
    Environment::publish();
    Environment::stripDotenv();
}

// Writable-path preparation is not gated on running under SKE. The directories
// are prepared exactly when the environment names paths for them, which is what
// makes this correct on any host, and a no-op on a normal server where
// storage/ and bootstrap/cache/ are already writable.
$skeUnwritablePaths = WritablePaths::boot();

if ($skeUnwritablePaths !== []) {
    // The application is about to fail on one of these with a message that
    // names the directory but not the reason. Saying so here — on stderr,
    // which the adapter forwards to the platform's log — is the only chance to
    // record that the directory could not be created at all.
    fwrite(STDERR, sprintf(
        "ske: could not create writable directories: %s\n",
        implode(', ', $skeUnwritablePaths),
    ));
}

unset($skeUnwritablePaths);
