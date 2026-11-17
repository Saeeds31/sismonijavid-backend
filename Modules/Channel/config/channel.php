<?php

return [
    'torob' => [
        'expected_audience' => env('TOROB_EXPECTED_AUDIENCE', 'api.sismonijavid.ir'),
        'enabled'           => env('TOROB_ENABLED', true),
        'per_page'          => 100,
    ],

    // آدرس base فرانت برای ساخت page_url
    'front_url' => env('FRONT_URL', 'https://sismonijavid.ir'),

    // آدرس base فایل‌های storage_public
    'storage_public_url' => env('STORAGE_PUBLIC_URL', 'https://api.sismonijavid.ir/storage_public'),
];
