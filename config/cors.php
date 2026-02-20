<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // ⚠️ IMPORTANTE: Solo l'URL del tuo frontend, SENZA slash finale
    'allowed_origins' => [
        'http://localhost:4200',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // ⚠️ FONDAMENTALE: Deve essere true per Angular+Laravel
    'supports_credentials' => true,
];
