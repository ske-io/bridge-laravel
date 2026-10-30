<?php

declare(strict_types=1);

use Ske\Bridge\Laravel\Octane\FramedRequest;

require_once __DIR__.'/../Fixtures/octane.php';

it('keeps a hostless path that starts with two slashes a path', function () {
    $request = FramedRequest::make([...octaneRequest('//evil.com/reset'), 'host' => '']);

    expect($request->getHost())->toBe('localhost');
    expect($request->getPathInfo())->toBe('//evil.com/reset');
});
