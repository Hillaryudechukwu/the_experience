<?php

/**
 * CORS for the mobile client and the web build.
 *
 * X-Guest-Token must be exposed explicitly: guest-first browsing depends on the
 * client reading the token the API mints on first contact, and browsers hide
 * non-simple response headers unless they are listed here.
 */
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*')),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-Guest-Token'],
    'max_age' => 3600,
    'supports_credentials' => false,
];
