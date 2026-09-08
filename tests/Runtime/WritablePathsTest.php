<?php

declare(strict_types=1);

use Ske\Bridge\Laravel\Runtime\WritablePaths;

function withSkeTempRoot(Closure $callback): void
{
    $root = sys_get_temp_dir().'/ske-bridge-'.bin2hex(random_bytes(6));

    try {
        $callback($root);
    } finally {
        removeSkeTempRoot($root);
    }
}

function removeSkeTempRoot(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }

    if (! is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            removeSkeTempRoot($path.'/'.$entry);
        }
    }

    @rmdir($path);
}

// --- prepare() -----------------------------------------------------------

it('creates the storage tree the environment points at', function () {
    withSkeTempRoot(function (string $root) {
        $storage = $root.'/storage';

        $failed = WritablePaths::prepare(['LARAVEL_STORAGE_PATH' => $storage]);

        expect($failed)->toBe([])
            ->and(is_dir($storage))->toBeTrue();

        foreach (WritablePaths::STORAGE_DIRECTORIES as $subdirectory) {
            expect(is_dir($storage.'/'.$subdirectory))->toBeTrue();
        }
    });
});

it('creates the parent directory of every bootstrap cache file', function () {
    withSkeTempRoot(function (string $root) {
        $env = [];
        foreach (WritablePaths::CACHE_VARIABLES as $index => $variable) {
            $env[$variable] = $root."/cache-{$index}/".strtolower($variable).'.php';
        }

        $failed = WritablePaths::prepare($env);

        expect($failed)->toBe([]);

        foreach ($env as $target) {
            expect(is_dir(dirname($target)))->toBeTrue();
        }
    });
});

it('leaves the package cache directory writable, which is what package discovery requires', function () {
    // Illuminate\Foundation\PackageManifest::write() refuses to write unless
    // is_writable(dirname($manifestPath)) — the check whose failure aborts
    // provider discovery and leaves the container with nothing bound.
    withSkeTempRoot(function (string $root) {
        $manifest = $root.'/cache/packages.php';

        WritablePaths::prepare(['APP_PACKAGES_CACHE' => $manifest]);

        expect(is_writable(dirname($manifest)))->toBeTrue();
    });
});

it('does nothing when the environment redirects nothing', function () {
    expect(WritablePaths::prepare([]))->toBe([]);
    expect(WritablePaths::prepare(['APP_ENV' => 'production']))->toBe([]);
});

it('is idempotent, so it costs nothing on a warm container', function () {
    withSkeTempRoot(function (string $root) {
        $env = ['LARAVEL_STORAGE_PATH' => $root.'/storage'];

        expect(WritablePaths::prepare($env))->toBe([])
            ->and(WritablePaths::prepare($env))->toBe([]);
    });
});

it('ignores empty and relative paths, which resolve against a working directory it cannot know', function () {
    withSkeTempRoot(function (string $root) {
        $failed = WritablePaths::prepare([
            'LARAVEL_STORAGE_PATH' => '',
            'APP_CONFIG_CACHE' => 'bootstrap/cache/config.php',
        ]);

        expect($failed)->toBe([])
            ->and(is_dir($root))->toBeFalse()
            ->and(is_dir('bootstrap/cache'))->toBeFalse();
    });
});

it('reports the directories it could not create', function () {
    withSkeTempRoot(function (string $root) {
        // A file where the directory needs to be: mkdir cannot win, and the
        // application is about to fail on it.
        mkdir($root, 0777, true);
        file_put_contents($root.'/cache', 'not a directory');

        $failed = WritablePaths::prepare(['APP_PACKAGES_CACHE' => $root.'/cache/packages.php']);

        expect($failed)->toBe([$root.'/cache']);
    });
});

it('reports each unwritable directory once', function () {
    withSkeTempRoot(function (string $root) {
        mkdir($root, 0777, true);
        file_put_contents($root.'/cache', 'not a directory');

        $failed = WritablePaths::prepare([
            'APP_PACKAGES_CACHE' => $root.'/cache/packages.php',
            'APP_SERVICES_CACHE' => $root.'/cache/services.php',
        ]);

        expect($failed)->toBe([$root.'/cache']);
    });
});

it('accepts a storage path with a trailing slash', function () {
    withSkeTempRoot(function (string $root) {
        WritablePaths::prepare(['LARAVEL_STORAGE_PATH' => $root.'/storage/']);

        expect(is_dir($root.'/storage/framework/views'))->toBeTrue()
            ->and(is_dir($root.'/storage/logs'))->toBeTrue();
    });
});

// --- publishStoragePath() ------------------------------------------------

it('publishes the storage path into the superglobals Laravel reads it from', function () {
    // Application::storagePath() looks in $_ENV and $_SERVER directly, not
    // through Env::get(), so a value that only exists in the real process
    // environment would be ignored and the read-only image storage/ used.
    $originalEnv = $_ENV;
    $originalServer = $_SERVER;

    try {
        unset($_ENV['LARAVEL_STORAGE_PATH'], $_SERVER['LARAVEL_STORAGE_PATH']);

        WritablePaths::publishStoragePath(['LARAVEL_STORAGE_PATH' => '/tmp/storage']);

        expect($_ENV['LARAVEL_STORAGE_PATH'])->toBe('/tmp/storage')
            ->and($_SERVER['LARAVEL_STORAGE_PATH'])->toBe('/tmp/storage');
    } finally {
        $_ENV = $originalEnv;
        $_SERVER = $originalServer;
    }
});

it('publishes nothing when no storage path is set', function () {
    $originalEnv = $_ENV;
    $originalServer = $_SERVER;

    try {
        unset($_ENV['LARAVEL_STORAGE_PATH'], $_SERVER['LARAVEL_STORAGE_PATH']);

        WritablePaths::publishStoragePath([]);

        expect($_ENV)->not->toHaveKey('LARAVEL_STORAGE_PATH')
            ->and($_SERVER)->not->toHaveKey('LARAVEL_STORAGE_PATH');
    } finally {
        $_ENV = $originalEnv;
        $_SERVER = $originalServer;
    }
});

// --- boot() --------------------------------------------------------------

it('prepares the paths named by the real environment', function () {
    withSkeTempRoot(function (string $root) {
        $originalEnv = $_ENV;
        $originalServer = $_SERVER;

        try {
            putenv('LARAVEL_STORAGE_PATH='.$root.'/storage');
            putenv('APP_PACKAGES_CACHE='.$root.'/cache/packages.php');
            unset(
                $_ENV['LARAVEL_STORAGE_PATH'],
                $_SERVER['LARAVEL_STORAGE_PATH'],
                $_ENV['APP_PACKAGES_CACHE'],
                $_SERVER['APP_PACKAGES_CACHE'],
            );

            $failed = WritablePaths::boot();

            expect($failed)->toBe([])
                ->and(is_dir($root.'/storage/framework/views'))->toBeTrue()
                ->and(is_writable($root.'/cache'))->toBeTrue()
                ->and($_SERVER['LARAVEL_STORAGE_PATH'])->toBe($root.'/storage');
        } finally {
            putenv('LARAVEL_STORAGE_PATH');
            putenv('APP_PACKAGES_CACHE');
            $_ENV = $originalEnv;
            $_SERVER = $originalServer;
        }
    });
});
