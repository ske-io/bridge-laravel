<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Http\Request;
use Illuminate\Queue\Queue;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Ske\Bridge\Laravel\Http\Controllers\SignedStorageUrlController;
use Ske\Bridge\Laravel\SkeServiceProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the disk was asked to sign, and what it answers with. Stands in for the
 * application's s3 disk, which needs the AWS SDK the bridge does not require.
 */
final class UploadSigner
{
    /** @var list<array{path: string, expiration: DateTimeInterface, options: array<string, mixed>}> */
    public array $calls = [];

    /** @param  array<string, mixed>  $headers */
    public function __construct(
        public array $headers = ['Host' => ['acme-uploads.s3.eu-west-1.amazonaws.com']],
    ) {}
}

/**
 * Runs a request against a real, booted Laravel application with the bridge
 * registered off the platform, then puts the container and facade globals back.
 *
 * Only the bootstrappers the route needs run: no error handler is installed,
 * so the suite's own stays in place. The route's middleware is emptied, since
 * the bare kernel has no 'web' group; which middleware the route carries is
 * covered against the provider directly.
 *
 * @param  array<string, mixed>  $input
 * @param  array<string, mixed>  $config
 * @param  Closure(Application): void|null  $prepare
 */
function postUpload(array $input = [], array $config = [], ?UploadSigner $signer = null, ?Closure $prepare = null): Response
{
    $container = Container::getInstance();
    $base = sys_get_temp_dir().'/ske-bridge-uploads-'.bin2hex(random_bytes(6));
    $files = new Filesystem;
    $files->ensureDirectoryExists("{$base}/bootstrap/cache");
    $files->ensureDirectoryExists("{$base}/storage/app");

    try {
        $app = Application::configure(basePath: $base)->withExceptions()->create();

        $app->afterBootstrapping(RegisterProviders::class, function (Application $app) use ($base, $config): void {
            $app->register(SkeServiceProvider::class);

            $app->make('config')->set([
                'session.driver' => 'array',
                'filesystems.disks.s3' => [
                    'driver' => 'local',
                    'root' => "{$base}/storage/app",
                    'bucket' => 'acme-uploads',
                    'serve' => false,
                ],
                'ske.uploads.middleware' => [],
                ...$config,
            ]);
        });

        $app->bootstrapWith([
            LoadConfiguration::class,
            RegisterFacades::class,
            RegisterProviders::class,
            BootProviders::class,
        ]);

        $signer ??= new UploadSigner;
        uploadDisk($app, 's3')->buildTemporaryUploadUrlsUsing(function (string $path, DateTimeInterface $expiration, array $options) use ($signer): array {
            $signer->calls[] = ['path' => $path, 'expiration' => $expiration, 'options' => $options];

            return ['url' => "https://acme-uploads.s3.eu-west-1.amazonaws.com/{$path}?X-Amz-Signature=sig", 'headers' => $signer->headers];
        });

        if ($prepare !== null) {
            $prepare($app);
        }

        $url = $app->make('config')->get('ske.uploads.url');

        return $app->make(HttpKernel::class)->handle(
            Request::create(is_string($url) ? $url : '', 'POST', $input, server: ['HTTP_ACCEPT' => 'application/json']),
        );
    } finally {
        // Booting the framework's providers leaves one static hook behind:
        // the context provider's queue payload callback, which would reach
        // for a facade root that is gone by the next test.
        Queue::createPayloadUsing(null);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance($container);
        $files->deleteDirectory($base);
    }
}

function uploadDisk(Application $app, string $name): FilesystemAdapter
{
    $disk = $app->make('filesystem')->disk($name);

    if (! $disk instanceof FilesystemAdapter) {
        throw new LogicException("The [{$name}] disk is not a filesystem adapter.");
    }

    return $disk;
}

/**
 * Lets every caller through, recording the arguments the gate was given.
 *
 * @param  list<mixed>|null  $arguments
 */
function allowUploads(?array &$arguments = null): Closure
{
    return function (Application $app) use (&$arguments): void {
        $app->make(Gate::class)->define('uploadFiles', function (?GenericUser $authenticated, mixed ...$given) use (&$arguments): bool {
            $arguments = $given;

            return true;
        });
    };
}

/** @return array<string, mixed> */
function responseBody(Response $response): array
{
    $data = json_decode((string) $response->getContent(), true);

    return is_array($data) ? $data : [];
}

/**
 * What a client posts when the caller passes no options: every field present,
 * every one an empty string.
 */
const EMPTY_FIELDS = ['bucket' => '', 'content_type' => '', 'cache_control' => '', 'expires' => '', 'visibility' => ''];

// --- the response -------------------------------------------------------

it('answers 201 with the uuid, bucket, key, url and headers', function () {
    $response = postUpload(['content_type' => 'image/png'] + EMPTY_FIELDS, prepare: allowUploads());
    $body = responseBody($response);

    expect($response->getStatusCode())->toBe(201)
        ->and(array_keys($body))->toBe(['uuid', 'bucket', 'key', 'url', 'headers'])
        ->and($body['bucket'])->toBe('acme-uploads')
        ->and($body['url'])->toBe("https://acme-uploads.s3.eu-west-1.amazonaws.com/tmp/{$body['uuid']}?X-Amz-Signature=sig")
        ->and($body['headers'])->toBe([
            'Host' => ['acme-uploads.s3.eu-west-1.amazonaws.com'],
            'Content-Type' => 'image/png',
        ]);
});

it('signs a fresh key under tmp/ for every request', function () {
    $signer = new UploadSigner;

    $first = responseBody(postUpload(EMPTY_FIELDS, signer: $signer, prepare: allowUploads()));
    $second = responseBody(postUpload(EMPTY_FIELDS, signer: $signer, prepare: allowUploads()));

    expect($first['uuid'])->toBeUuid()
        ->and($first['key'])->toBe("tmp/{$first['uuid']}")
        ->and($signer->calls[0]['path'])->toBe($first['key'])
        ->and($second['key'])->not->toBe($first['key']);
});

it('passes the signed headers through untouched when they carry a Content-Type', function () {
    $signer = new UploadSigner(['Host' => ['acme-uploads.s3.amazonaws.com'], 'content-type' => ['text/csv']]);

    $body = responseBody(postUpload(['content_type' => 'text/csv'], signer: $signer, prepare: allowUploads()));

    expect($body['headers'])->toBe(['Host' => ['acme-uploads.s3.amazonaws.com'], 'content-type' => ['text/csv']]);
});

// --- what is signed -----------------------------------------------------

it('signs no ACL, only the content headers that were asked for', function () {
    $signer = new UploadSigner;

    postUpload(EMPTY_FIELDS, signer: $signer, prepare: allowUploads());
    postUpload([
        'content_type' => 'image/png',
        'cache_control' => 'max-age=3600',
        'expires' => 'Wed, 21 Oct 2026 07:28:00 GMT',
        'visibility' => 'private',
    ], signer: $signer, prepare: allowUploads());

    expect($signer->calls[0]['options'])->toBe(['ContentType' => 'application/octet-stream'])
        ->and($signer->calls[1]['options'])->toBe([
            'ContentType' => 'image/png',
            'CacheControl' => 'max-age=3600',
            'Expires' => 'Wed, 21 Oct 2026 07:28:00 GMT',
        ]);
});

it('signs the URL for the configured number of minutes', function () {
    Carbon::setTestNow('2026-10-04 12:00:00');
    $signer = new UploadSigner;

    try {
        postUpload(signer: $signer, prepare: allowUploads());
        postUpload(config: ['ske.uploads.expires_after' => 30], signer: $signer, prepare: allowUploads());
    } finally {
        Carbon::setTestNow();
    }

    expect($signer->calls[0]['expiration']->format('H:i'))->toBe('12:05')
        ->and($signer->calls[1]['expiration']->format('H:i'))->toBe('12:30');
});

it('signs with the configured disk and reports its bucket', function () {
    $body = responseBody(postUpload(config: [
        'ske.uploads.disk' => 'media',
        'filesystems.disks.media' => ['driver' => 'local', 'root' => sys_get_temp_dir(), 'bucket' => 'acme-media', 'serve' => false],
    ], prepare: function (Application $app): void {
        allowUploads()($app);
        uploadDisk($app, 'media')->buildTemporaryUploadUrlsUsing(fn (string $path): array => ['url' => "https://acme-media/{$path}", 'headers' => []]);
    }));

    expect($body['bucket'])->toBe('acme-media')
        ->and($body['url'])->toBe("https://acme-media/{$body['key']}");
});

// --- validation ---------------------------------------------------------

it('accepts the disk bucket, an empty one or none', function (array $input) {
    expect(postUpload($input, prepare: allowUploads())->getStatusCode())->toBe(201);
})->with([
    'matching' => [['bucket' => 'acme-uploads']],
    'empty' => [['bucket' => '']],
    'missing' => [[]],
]);

it('refuses any other bucket', function () {
    $signer = new UploadSigner;

    $response = postUpload(['bucket' => 'someone-elses-bucket'], signer: $signer, prepare: allowUploads());

    expect($response->getStatusCode())->toBe(422)
        ->and(array_keys(responseBody($response)['errors']))->toBe(['bucket'])
        ->and($signer->calls)->toBe([]);
});

it('refuses any visibility but private', function () {
    $signer = new UploadSigner;

    $response = postUpload(['visibility' => 'public-read'], signer: $signer, prepare: allowUploads());

    expect($response->getStatusCode())->toBe(422)
        ->and(array_keys(responseBody($response)['errors']))->toBe(['visibility'])
        ->and($signer->calls)->toBe([]);
});

// --- the gate -----------------------------------------------------------

it('refuses with 403 when the application defines no gate', function () {
    $signer = new UploadSigner;

    $response = postUpload(EMPTY_FIELDS, signer: $signer);

    expect($response->getStatusCode())->toBe(403)
        ->and($signer->calls)->toBe([]);
});

it('hands the gate the user and the bucket', function () {
    $user = new GenericUser(['id' => 7]);

    postUpload(EMPTY_FIELDS, prepare: function (Application $app) use ($user, &$arguments): void {
        // A guard that answers this user for any request, standing in for
        // the session the 'web' middleware would start.
        $app->make('auth')->viaRequest('signed-in', fn (): GenericUser => $user);
        $app->make('config')->set('auth.guards.web', ['driver' => 'signed-in']);
        allowUploads($arguments)($app);
    });

    expect($arguments)->toBe([$user, 'acme-uploads']);
});

it('lets a gate decide for a guest', function () {
    $response = postUpload(EMPTY_FIELDS, prepare: allowUploads($arguments));

    expect($response->getStatusCode())->toBe(201)
        ->and($arguments)->toBe([null, 'acme-uploads']);
});

it('checks the configured ability', function () {
    $response = postUpload(config: ['ske.uploads.gate' => 'uploadAvatars'], prepare: function (Application $app): void {
        allowUploads()($app);
        $app->make(Gate::class)->define('uploadAvatars', fn (?GenericUser $user): bool => false);
    });

    expect($response->getStatusCode())->toBe(403);
});

// --- the route ----------------------------------------------------------

/**
 * The provider against the same bare application its own tests use, off the
 * platform, returning the routes it registered.
 *
 * @param  array<string, mixed>  $uploads
 * @return list<Route>
 */
function uploadRoutes(array $uploads = []): array
{
    $container = Container::getInstance();

    try {
        $app = new Application(sys_get_temp_dir());
        $app->instance('config', new Repository($uploads === [] ? [] : ['ske' => ['uploads' => $uploads]]));
        $app->instance('files', new Filesystem);
        $app->instance('router', new Router(new Dispatcher($app), $app));
        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();

        $provider = new SkeServiceProvider($app);
        $provider->register();
        $provider->boot();

        return array_values($app->make('router')->getRoutes()->getRoutes());
    } finally {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance($container);
    }
}

it('registers the route off the platform, behind the web middleware', function () {
    expect(SkeServiceProvider::runningOnPlatform())->toBeFalse();

    $routes = uploadRoutes();

    expect($routes)->toHaveCount(1)
        ->and($routes[0]->uri())->toBe('ske/signed-storage-url')
        ->and($routes[0]->methods())->toBe(['POST'])
        ->and($routes[0]->middleware())->toBe(['web'])
        ->and($routes[0]->getActionName())->toBe(SignedStorageUrlController::class);
});

it('serves a custom path and middleware when configured to', function () {
    $routes = uploadRoutes([
        'enabled' => true,
        'url' => '/uploads/sign',
        'middleware' => ['web', 'auth'],
    ]);

    expect($routes)->toHaveCount(1)
        ->and($routes[0]->uri())->toBe('uploads/sign')
        ->and($routes[0]->middleware())->toBe(['web', 'auth']);
});

it('registers no route when uploads are turned off', function () {
    expect(uploadRoutes(['enabled' => false]))->toBe([]);
});

it('ships its config, publishable on its own tag', function () {
    $config = require __DIR__.'/../../../config/ske.php';

    expect($config['uploads'])->toBe([
        'enabled' => true,
        'url' => '/ske/signed-storage-url',
        'middleware' => ['web'],
        'gate' => 'uploadFiles',
        'expires_after' => 5,
        'disk' => 's3',
    ])
        ->and(SkeServiceProvider::pathsToPublish(SkeServiceProvider::class, 'ske-config'))->toHaveCount(1);
});
