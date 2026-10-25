<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Octane\OctaneServiceProvider;
use Ske\Bridge\Laravel\SkeServiceProvider;

$app = Application::configure($_SERVER['APP_BASE_PATH'])
    ->withProviders([OctaneServiceProvider::class, SkeServiceProvider::class])
    ->withRouting(using: function (): void {
        Route::get('/identity', fn () => response()->json(['user' => auth('fixture')->id(), 'request_id' => request()->header('X-Request-Id')]));
        Route::get('/basic-auth', fn (Request $request) => response()->json([
            'user' => $request->getUser(), 'password' => $request->getPassword(),
            'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        ]));
        Route::get('/stream', fn () => response()->stream(function (): void {
            echo request()->headers->get('X-Value').':'.request()->path();
        }));
        Route::get('/large-stream', fn () => response()->stream(fn () => print str_repeat('x', 32768)));
        Route::get('/download', function () {
            $path = app()->basePath('download.bin');
            file_put_contents($path, '0123456789');

            return response()->download($path)->deleteFileAfterSend();
        });
        Route::get('/terminate-error', function () {
            app()->terminating(fn () => throw new RuntimeException('termination failed'));

            return 'captured success';
        });
        Route::get('/termination-barrier', function () {
            $directory = app()->basePath();
            app()->terminating(function () use ($directory): void {
                file_put_contents($directory.'/termination-started', 'started');

                while (! is_file($directory.'/termination-release')) {
                    usleep(1000);
                }

                file_put_contents($directory.'/termination-done', 'done');
            });

            return 'ready';
        });
        Route::post('/upload', function (Request $request) {
            $file = $request->file('documents.receipt');

            if (! $file instanceof UploadedFile) {
                throw new RuntimeException('Missing receipt upload.');
            }

            return response()->json([
                'tags' => $request->input('tags'), 'valid' => $file->isValid(),
                'name' => $file->getClientOriginalName(), 'bytes' => base64_encode($file->getContent()),
                'native_name' => $_FILES['documents']['name']['receipt'] ?? null,
            ]);
        });
        Route::middleware('web')->group(function (): void {
            Route::get('/form', fn (Request $request) => response()->json([
                'errors' => $request->session()->get('errors')?->any() ?? false,
            ]));
            Route::post('/form', function (Request $request) {
                $request->validate(['email' => 'required|email']);

                return redirect('/form');
            });
        });
    })
    ->withMiddleware(fn (Middleware $middleware) => $middleware->validateCsrfTokens(except: ['*']))
    ->withExceptions()
    ->create();
$app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
    $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    $app['config']->set('app.env', 'testing');
    $app['config']->set('logging.default', 'single');
    $app['config']->set('logging.channels.single.path', $app->basePath('worker.log'));
    $app['config']->set('cache.default', 'array');
    $app['config']->set('auth.guards.fixture', ['driver' => 'fixture']);
});
$app->booted(function (): void {
    Auth::viaRequest('fixture', fn (Request $request) => $request->header('X-User') ? new GenericUser(['id' => $request->header('X-User')]) : null);
});

return $app;
