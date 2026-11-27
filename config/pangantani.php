<?php
return [
    'frontend_url' => rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/'),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
    'payment_ttl' => (int) env('PAYMENT_TTL_MINUTES', 30),
    'closing_soon_hours' => (int) env('CLOSING_SOON_HOURS', 24),
    // Cookie HttpOnly pembawa token Sanctum untuk frontend browser (Next.js).
    'auth_cookie' => [
        'name' => env('AUTH_COOKIE_NAME', 'pangantani_token'),
        'domain' => env('AUTH_COOKIE_DOMAIN'),
        'secure' => env('AUTH_COOKIE_SECURE'), // null = otomatis aktif di production
        'same_site' => strtolower((string) env('AUTH_COOKIE_SAME_SITE', 'lax')),
    ],
    'midtrans' => ['server_key' => env('MIDTRANS_SERVER_KEY'), 'client_key' => env('MIDTRANS_CLIENT_KEY'), 'production' => (bool) env('MIDTRANS_IS_PRODUCTION', false)],
];
