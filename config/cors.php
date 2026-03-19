<?php
return [
    'paths' => [
        'api/*',
        'broadcasting/auth',
        'storage/*',
        'songs/*',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:8081',
        'http://localhost:*',     // wildcard for dev ports
    ],

    'allowed_origins_patterns' => [
        '/^https?:\/\/.*\.trycloudflare\.com$/',  // ← allow http & https, any subdomain
        '/^https?:\/\/.*\.ngrok-free.dev',        // allow from ngrok
        '/^https?:\/\/localhost(:[0-9]+)?$/',     // extra safety for local,
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,  // Change to false unless you really need cookies/auth
];