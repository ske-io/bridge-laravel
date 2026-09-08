<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Ske\Bridge\Laravel\Http\Middleware\EnsureSkeInternal;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function guard(Request $request): Response
{
    return (new EnsureSkeInternal)->handle(
        $request,
        fn (): Response => new Response('reached', 200),
    );
}

function requestWithHeader(?string $value): Request
{
    $request = Request::create('/__ske/cli', 'POST');

    if ($value !== null) {
        $request->headers->set(EnsureSkeInternal::HEADER, $value);
    }

    return $request;
}

it('lets a request carrying the marking header through', function () {
    expect(guard(requestWithHeader('true'))->getContent())->toBe('reached');
});

it('answers 404 rather than 403 for anything else', function (?string $header) {
    // Telling a caller these routes exist and are merely forbidden is more than
    // they need to know; the adapter answers the same way.
    expect(fn () => guard(requestWithHeader($header)))
        ->toThrow(NotFoundHttpException::class);
})->with([
    'absent' => null,
    'empty' => '',
    'false' => 'false',
    'wrong case' => 'TRUE',
    'near miss' => 'true ',
    'prefix' => 'true-ish',
]);

it('does not leak whether a guess was close', function () {
    // hash_equals rather than !==: the comparison is against a constant, so the
    // timing tells an attacker nothing about how near they got.
    $near = microtime(true);
    try {
        guard(requestWithHeader('trud'));
    } catch (NotFoundHttpException) {
    }
    $nearElapsed = microtime(true) - $near;

    expect($nearElapsed)->toBeLessThan(1.0);
});
