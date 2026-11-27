<?php
// Dijalankan oleh PasswordResetConcurrencyTest sebagai proses terpisah. Bukan endpoint publik.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create($argv[3] ?? '/api/v1/auth/reset-password', 'POST', server: [
    'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.0.0.'.(int) $argv[2],
], content: base64_decode($argv[1]));
$response = $kernel->handle($request);
echo $response->getStatusCode();
$kernel->terminate($request, $response);
