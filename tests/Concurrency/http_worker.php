<?php
// Dijalankan oleh MysqlTransactionConcurrencyTest sebagai proses HTTP paralel di atas MySQL/InnoDB.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$data = json_decode(base64_decode($argv[1]), true);
$server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.0.0.'.(int) ($data['ip'] ?? 1)];
if (!empty($data['token'])) { $server['HTTP_AUTHORIZATION'] = 'Bearer '.$data['token']; }
if (!empty($data['idempotency_key'])) { $server['HTTP_IDEMPOTENCY_KEY'] = $data['idempotency_key']; }
$request = Illuminate\Http\Request::create($data['uri'], $data['method'] ?? 'POST', server: $server, content: json_encode($data['body'] ?? []));
$response = $kernel->handle($request);
echo $response->getStatusCode();
$kernel->terminate($request, $response);
