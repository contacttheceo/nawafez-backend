<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://nawafez-frontend.vercel.app',
        'https://nawafez.vercel.app',
        'https://nwafizlogi.com',
        'https://www.nwafizlogi.com',
        env('FRONTEND_URL', 'https://www.nwafizlogi.com'),
    ],

    'allowed_origins_patterns' => [
        'https://nawafez-.*\.vercel\.app',  // preview deployments
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
