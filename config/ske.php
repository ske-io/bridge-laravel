<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Direct uploads
    |--------------------------------------------------------------------------
    |
    | The endpoint the frontend asks for a signed URL, so a browser can PUT a
    | file straight to the bucket instead of through the application.
    |
    */

    'uploads' => [

        // Registers the route, on and off the platform, so uploads work the
        // same locally. Turn it off if the application has no direct uploads.
        'enabled' => true,

        // The path the frontend posts to for a signed upload URL.
        'url' => '/ske/signed-storage-url',

        // The route's middleware. 'web' gives the gate the session's user.
        'middleware' => ['web'],

        // The ability checked as Gate::authorize($gate, [$user, $bucket]).
        // With nothing defined for it, every request is refused with a 403.
        'gate' => 'uploadFiles',

        // How long a signed URL stays valid, in minutes.
        'expires_after' => 5,

        // The disk whose bucket receives the files, under tmp/.
        'disk' => 's3',

    ],

];
