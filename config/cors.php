<?php
// supports_credentials=true agar browser frontend boleh menyimpan/mengirim cookie autentikasi.
// Wajib origin eksplisit (bukan '*') ketika credentials diaktifkan.
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000'))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Idempotency-Key'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => true,
];
