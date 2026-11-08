<?php

return [

    'default' => env('PAYMENT_DRIVER', 'zibal'),
    'front_url' => env('FRONT_URL'),
    'drivers' => [

        'zibal' => [
            'merchant' => env('ZIBAL_MERCHANT'),
            'sandbox' => env('ZIBAL_SANDBOX', true),
        ],
        'zarinpal' => [
            'merchant' => env('ZARINPAL_MERCHANT'),
            'sandbox' => env('ZARINPAL_SANDBOX', true),
        ],
        'parsian' => [
            'merchant' => env('PARSIAN_MERCHANT'),
            'terminal' => env('PARSIAN_TERMINAL'),
            'login_account' => env('PARSIAN_LOGIN_ACCOUNT'),
            'sandbox' => env('PARSIAN_SANDBOX', true),
        ],
        'snapppay' => [
            'base_url' => env('SNAPPPAY_BASE_URL', 'https://api.snapppay.ir'),
            'client_id' => env('SNAPPPAY_CLIENT_ID'),
            'client_secret' => env('SNAPPPAY_CLIENT_SECRET'),
            'username' => env('SNAPPPAY_USERNAME'),
            'password' => env('SNAPPPAY_PASSWORD'),
        ],
    ],

];
