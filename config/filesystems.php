<?php

return [

    'default' => env('FILESYSTEM_DISK', 'public'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root'   => storage_path('app/private'),
            'serve'  => true,
            'throw'  => false,
        ],

        'public' => [
            'driver'     => 'local',
            'root'       => storage_path('app/public'),
            'url'        => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw'      => false,
        ],

        // For production: Cloudflare R2 (S3-compatible)
        // 'r2' => [
        //     'driver'   => 's3',
        //     'key'      => env('R2_ACCESS_KEY_ID'),
        //     'secret'   => env('R2_SECRET_ACCESS_KEY'),
        //     'region'   => 'auto',
        //     'bucket'   => env('R2_BUCKET'),
        //     'url'      => env('R2_URL'),
        //     'endpoint' => env('R2_ENDPOINT'),
        //     'use_path_style_endpoint' => false,
        // ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
