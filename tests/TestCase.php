<?php
namespace Tests;
abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    public function createApplication()
    {
        // Test setup must never run RefreshDatabase against the owner's MySQL database.
        foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'MIDTRANS_SERVER_KEY' => 'test-server-key', 'MIDTRANS_IS_PRODUCTION' => 'false', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='] as $name => $value) {
            putenv($name.'='.$value); $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $app = parent::createApplication();
        if ($app->configurationIsCached() || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \LogicException('Pengujian hanya diizinkan pada SQLite :memory: tanpa config cache.');
        }
        // Some Windows SQLite builds omit trig functions that MySQL provides natively.
        $pdo = $app['db']->connection()->getPdo();
        foreach (['RADIANS' => 'deg2rad', 'SIN' => 'sin', 'COS' => 'cos', 'ASIN' => 'asin', 'SQRT' => 'sqrt'] as $sql => $php) {
            $pdo->sqliteCreateFunction($sql, static fn ($value) => $value === null ? null : $php((float) $value), 1);
        }
        $pdo->sqliteCreateFunction('POWER', static fn ($x, $y) => $x === null || $y === null ? null : pow((float) $x, (float) $y), 2);
        return $app;
    }
}
