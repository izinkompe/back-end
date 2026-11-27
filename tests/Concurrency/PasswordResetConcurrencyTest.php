<?php
namespace Tests\Concurrency;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use App\Models\User;
use Illuminate\Support\Facades\{Artisan, DB, Hash, Password};

/** Uji konkurensi nyata pada MySQL/MariaDB khusus pengujian. Tidak ikut test suite SQLite biasa. */
class PasswordResetConcurrencyTest extends TestCase
{
    private array $env;

    protected function setUp(): void
    {
        $db = getenv('PT_CONCURRENCY_DB_DATABASE') ?: 'pangantani_test';
        if (!str_ends_with($db, '_test')) { throw new \LogicException('Database uji konkurensi wajib berakhiran _test.'); }
        $this->env = [
            'APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'DB_CONNECTION' => getenv('PT_CONCURRENCY_DB_CONNECTION') ?: 'mysql', 'DB_HOST' => getenv('PT_CONCURRENCY_DB_HOST') ?: '127.0.0.1',
            'DB_PORT' => getenv('PT_CONCURRENCY_DB_PORT') ?: '3306', 'DB_DATABASE' => $db,
            'DB_USERNAME' => getenv('PT_CONCURRENCY_DB_USERNAME') ?: 'root', 'DB_PASSWORD' => (string) getenv('PT_CONCURRENCY_DB_PASSWORD'),
            'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ];
        foreach ($this->env as $k => $v) { putenv("$k=$v"); $_ENV[$k] = $_SERVER[$k] = $v; }
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if ($app->configurationIsCached()) { throw new \LogicException('Hapus config cache sebelum uji konkurensi.'); }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_two_simultaneous_resets_with_same_token_only_one_succeeds(): void
    {
        $user = User::create(['name' => 'Pembeli', 'email' => 'race@pangantani.test', 'role' => 'buyer', 'password' => 'Password123']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->createToken('old-session');
        $token = Password::createToken($user);
        $passwords = [1 => 'FirstWinner123', 2 => 'SecondWinner123'];

        DB::beginTransaction();
        DB::table('password_reset_tokens')->where('email', $user->email)->lockForUpdate()->first();
        $workers = [];
        foreach ($passwords as $i => $pass) {
            $payload = base64_encode(json_encode(['email' => $user->email, 'token' => $token, 'password' => $pass, 'password_confirmation' => $pass]));
            $workers[$i] = new Process([PHP_BINARY, __DIR__.'/reset_password_worker.php', $payload, (string) $i], dirname(__DIR__, 2), $this->env, null, 60);
            $workers[$i]->start();
        }
        sleep(2);
        DB::rollBack();

        $status = [];
        foreach ($workers as $i => $p) { $p->wait(); $status[$i] = (int) trim($p->getOutput()); $this->assertSame('', trim($p->getErrorOutput())); }
        $sorted = array_values($status); sort($sorted);
        $this->assertSame([200, 422], $sorted, 'Hanya satu permintaan boleh berhasil.');

        $winner = array_search(200, $status, true);
        $this->assertTrue(Hash::check($passwords[$winner], $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    public function test_login_cannot_issue_a_token_for_a_password_changed_during_login(): void
    {
        $user = User::create(['name' => 'Pembeli', 'email' => 'login-race@pangantani.test', 'role' => 'buyer', 'password' => 'Password123']);
        DB::beginTransaction();
        $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        $locked->update(['password' => 'Changed12345']);
        $payload = base64_encode(json_encode(['email' => $user->email, 'password' => 'Password123']));
        $worker = new Process([PHP_BINARY, __DIR__.'/reset_password_worker.php', $payload, '1', '/api/v1/auth/login'], dirname(__DIR__, 2), $this->env, null, 60);
        $worker->start();
        sleep(2);
        DB::commit();
        $worker->wait();

        $this->assertSame('', trim($worker->getErrorOutput()));
        $this->assertSame(422, (int) trim($worker->getOutput()));
        $this->assertSame(0, $user->tokens()->count());
    }
}
