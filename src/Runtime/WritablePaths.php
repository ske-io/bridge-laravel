<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Runtime;

/**
 * Creates the writable directories Laravel expects to already exist.
 *
 * A serverless image is read-only apart from /tmp, so a deployment points the
 * framework's writable paths there with `LARAVEL_STORAGE_PATH` and the
 * `APP_*_CACHE` variables. Laravel writes to those paths but never creates
 * them, and /tmp starts empty on every cold start, so something has to make
 * the directories first.
 *
 * That "something" cannot be a service provider. `PackageManifest` — the class
 * that *discovers* providers — writes its manifest to `APP_PACKAGES_CACHE`
 * while the `RegisterFacades` bootstrapper runs, long before any provider is
 * registered. With the directory missing it throws "The /tmp/cache directory
 * must be present and writable", package discovery is abandoned, and the
 * application ends up with no bound services at all — which surfaces as
 * "Target class [view] does not exist" from the exception handler trying to
 * render the failure. A provider that prepares the directories can never run
 * early enough to prevent it.
 *
 * So this runs from the package's Composer `autoload.files` entry (see
 * bootstrap.php), which executes when the application requires
 * vendor/autoload.php — before bootstrap/app.php, and therefore before any of
 * the framework exists.
 */
final class WritablePaths
{
    /**
     * Subdirectories Laravel writes to under its storage path.
     *
     * @var list<string>
     */
    public const STORAGE_DIRECTORIES = [
        'app/public',
        'framework/cache/data',
        'framework/sessions',
        'framework/views',
        'logs',
    ];

    /**
     * The bootstrap cache files whose location the environment overrides.
     *
     * Laravel resolves each of these through `Application::normalizeCachePath()`,
     * so a deployment can move them out of the read-only image one by one.
     *
     * @var list<string>
     */
    public const CACHE_VARIABLES = [
        'APP_PACKAGES_CACHE',
        'APP_SERVICES_CACHE',
        'APP_CONFIG_CACHE',
        'APP_ROUTES_CACHE',
        'APP_EVENTS_CACHE',
    ];

    /**
     * Prepares this process for the paths its own environment names.
     *
     * This is the entry point bootstrap.php calls. Only the handful of
     * variables involved are read, rather than the whole environment, because
     * under php-fpm this runs once per request.
     *
     * @return list<string> the directories that could not be created
     */
    public static function boot(): array
    {
        $env = [];

        foreach ([...self::CACHE_VARIABLES, 'LARAVEL_STORAGE_PATH'] as $variable) {
            if (($value = Environment::get($variable)) !== null) {
                $env[$variable] = $value;
            }
        }

        self::publishStoragePath($env);

        return self::prepare($env);
    }

    /**
     * Ensures every directory the given environment redirects Laravel to.
     *
     * Nothing is created when the environment redirects nothing: on a normal
     * server the framework's own storage/ and bootstrap/cache/ are writable
     * and already there, so this is a no-op outside a serverless image.
     *
     * @param  array<string, string>  $env
     * @return list<string> the directories that could not be created, so the
     *                      caller can say so while the application still boots
     *                      far enough to report the real error
     */
    public static function prepare(array $env): array
    {
        $failed = [];

        if (($storage = self::path($env, 'LARAVEL_STORAGE_PATH')) !== null) {
            // The storage root itself matters: Laravel resolves storage_path()
            // against it even for paths this list does not name.
            foreach ([$storage, ...self::storageSubdirectories($storage)] as $directory) {
                if (! self::ensure($directory)) {
                    $failed[] = $directory;
                }
            }
        }

        foreach (self::CACHE_VARIABLES as $variable) {
            if (($target = self::path($env, $variable)) === null) {
                continue;
            }

            $directory = dirname($target);
            if (! self::ensure($directory)) {
                $failed[] = $directory;
            }
        }

        return array_values(array_unique($failed));
    }

    /**
     * Mirrors the storage path into the superglobals the framework reads it from.
     *
     * `Application::storagePath()` looks the value up in `$_ENV` and `$_SERVER`
     * directly rather than through `Env::get()`, so a value that only exists in
     * the real process environment — all a queue or console worker started by
     * the adapter has — is invisible to it, and the application silently falls
     * back to the read-only storage/ directory inside the image.
     *
     * @param  array<string, string>  $env
     */
    public static function publishStoragePath(array $env): void
    {
        if (($storage = self::path($env, 'LARAVEL_STORAGE_PATH')) === null) {
            return;
        }

        $_ENV['LARAVEL_STORAGE_PATH'] = $storage;
        $_SERVER['LARAVEL_STORAGE_PATH'] = $storage;
    }

    /** @return list<string> */
    private static function storageSubdirectories(string $storage): array
    {
        return array_map(
            static fn (string $subdirectory): string => $storage.'/'.$subdirectory,
            self::STORAGE_DIRECTORIES,
        );
    }

    /**
     * Reads an absolute path from the environment.
     *
     * Only absolute values are acted on. Laravel resolves a relative
     * `APP_*_CACHE` against the application's base path, so creating a
     * directory for one here would mean guessing at a working directory that
     * differs between php-fpm, the console and the queue.
     *
     * @param  array<string, string>  $env
     */
    private static function path(array $env, string $variable): ?string
    {
        $value = trim($env[$variable] ?? '');

        if ($value === '' || ! str_starts_with($value, '/')) {
            return null;
        }

        return rtrim($value, '/');
    }

    /**
     * Creates a directory if it is not already there, reporting whether it now exists.
     *
     * The mode is deliberately wide: the process that creates the directory is
     * not always the one that writes to it (an adapter entrypoint starts
     * php-fpm, whose workers may run as another user), and /tmp is private to
     * one execution environment anyway.
     *
     * The second is_dir() covers the race where a concurrent worker created the
     * same directory between the check and the mkdir.
     */
    private static function ensure(string $directory): bool
    {
        if (file_exists($directory)) {
            // Already taken: a directory is what was wanted, and a file at
            // that path is not something mkdir can do anything about.
            return is_dir($directory);
        }

        return @mkdir($directory, 0777, true) || is_dir($directory);
    }
}
