<?php

/**
 * TPIX TRADE - CORS Configuration.
 *
 * Restrict cross-origin requests to known origins only.
 */

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', env('APP_URL', 'http://localhost:8000'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'X-Requested-With', 'Authorization', 'Accept', 'X-CSRF-TOKEN', 'X-API-Key'],

    'exposed_headers' => [],

    'max_age' => 86400, // 24 hours preflight cache

    'supports_credentials' => true,
];
