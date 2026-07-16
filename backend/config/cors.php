<?php

// CORS policy. The framework default allows every origin ('*'); this restricts
// browser cross-origin access to the SPA origin (APP_FRONTEND_URL) plus the
// local dev hosts. Auth is bearer-token (localStorage), not cookies, so
// credentialed requests are not required.
//
// In production set APP_FRONTEND_URL to the real SPA host. Additional origins
// (e.g. a staging host) can be added via CORS_ALLOWED_ORIGINS as a comma list.

$origins = array_values(array_filter(array_unique(array_merge(
    [config('app.frontend_url')],
    // Local development hosts.
    [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ],
    // Optional extra origins from env (comma-separated).
    array_map('trim', array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))
))));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
