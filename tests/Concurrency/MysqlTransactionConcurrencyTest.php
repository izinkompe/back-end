<?php
namespace Tests\Concurrency;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use App\Models\{User, Category, Address, Offer, PickupSlot, Order, Distribution, EmissionFactor};
use App\Services\Midtrans;
use Illuminate\Support\Facades\{Artisan, DB};
use Laravel\Sanctum\Sanctum;

/**
 * Pengujian MySQL/MariaDB InnoDB khusus pengujian:
 * 1. Dua pembeli memesan masing-masing 7 kg dari stok 10 kg secara bersamaan: hanya satu berhasil.
 * 2. Retry checkout tidak membuat pesanan ganda.
 * 3. Pembatalan/expiry tidak melepaskan stok dua kali.
 * 4. Konfirmasi dan koreksi laporan tidak menggandakan penyaluran.
 */
class MysqlTransactionConcurrencyTest extends TestCase
{
    private array $env;
    private User $buyer1;
    private User $buyer2;
    private User $farmer;
    private User $admin;
    private Offer $offer;
    private PickupSlot $slot;

    protected function setUp(): void
    {
        $db = getenv('PT_CONCURRENCY_DB_DATABASE') ?: 'pangantani_test';
        if (!str_ends_with($db, '_test')) { throw new \LogicException('Database uji wajib berakhiran _test.'); }
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
        if ($app->configurationIsCached()) { throw new \LogicException('Hapus config cache sebelum uji MySQL.'); }
        Artisan::call('migrate:fresh', ['--force' => true]);

        $this->buyer1 = User::create(['name' => 'Pembeli 1', 'email' => 'b1@mysql.test', 'role' => 'buyer', 'password' => 'Password123']);
        $this->buyer2 = User::create(['name' => 'Pembeli 2', 'email' => 'b2@mysql.test', 'role' => 'buyer', 'password' => 'Password123']);
        $this->farmer = User::create(['name' => 'Petani', 'email' => 'farmer@mysql.test', 'role' => 'farmer', 'password' => 'Password123']);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@mysql.test', 'role' => 'admin', 'password' => 'Password123']);
        foreach ([$this->buyer1, $this->buyer2, $this->farmer, $this->admin] as $user) { $user->forceFill(['email_verified_at' => now()])->save(); }
        $cat = Category::create(['name' => 'Sayuran']);
        EmissionFactor::create(['category_id' => $cat->id, 'kg_co2e_per_kg' => 2, 'source' => 'Uji MySQL', 'version' => '1', 'method' => 'Berat x faktor', 'assumptions' => 'Uji']);
        $addr = Address::create(['user_id' => $this->farmer->id, 'label' => 'Kebun', 'address' => 'Bogor', 'area' => 'Bogor', 'latitude' => -6.5, 'longitude' => 106.8]);
        $this->offer = Offer::create(['farmer_id' => $this->farmer->id, 'category_id' => $cat->id, 'address_id' => $addr->id, 'name' => 'Bayam 10kg', 'commodity' => 'Bayam', 'condition' => 'Segar', 'stock_g' => 10000, 'price_per_kg' => 10000, 'minimum_price' => 5000, 'closes_at' => now()->addHours(2), 'status' => 'active', 'area' => 'Bogor', 'latitude' => -6.5, 'longitude' => 106.8]);
        $this->slot = PickupSlot::create(['offer_id' => $this->offer->id, 'starts_at' => now()->addHours(3), 'ends_at' => now()->addHours(4)]);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_two_buyers_ordering_7kg_from_10kg_stock_simultaneously_only_one_succeeds(): void
    {
        $t1 = $this->buyer1->createToken('b1')->plainTextToken;
        $t2 = $this->buyer2->createToken('b2')->plainTextToken;
        $body = ['offer_id' => $this->offer->id, 'pickup_slot_id' => $this->slot->id, 'quantity_kg' => '7.000', 'expected_price_per_kg' => 10000];

        // Tahan lock baris offer di InnoDB agar kedua proses worker mengantre bersamaan pada lock yang sama.
        DB::beginTransaction();
        Offer::whereKey($this->offer->id)->lockForUpdate()->first();
        $workers = [];
        foreach ([1 => [$t1, 'key-buyer-1'], 2 => [$t2, 'key-buyer-2']] as $i => [$token, $key]) {
            $arg = base64_encode(json_encode(['uri' => '/api/v1/orders', 'method' => 'POST', 'token' => $token, 'idempotency_key' => $key, 'body' => $body, 'ip' => $i]));
            $workers[$i] = new Process([PHP_BINARY, __DIR__.'/http_worker.php', $arg], dirname(__DIR__, 2), $this->env, null, 60);
            $workers[$i]->start();
        }
        sleep(2);
        DB::rollBack();

        $statuses = [];
        foreach ($workers as $i => $p) { $p->wait(); $statuses[$i] = (int) trim($p->getOutput()); $this->assertSame('', trim($p->getErrorOutput())); }
        sort($statuses);
        $this->assertSame([201, 409], $statuses, 'Dari 2 pembeli yang memesan 7 kg atas stok 10 kg, hanya 1 boleh berhasil.');
        $this->assertSame(1, Order::count());
        $this->assertSame(7000, $this->offer->fresh()->reserved_g);
        $this->assertSame(3000, $this->offer->fresh()->available_g);
    }

    public function test_checkout_retries_do_not_create_duplicate_orders_on_mysql(): void
    {
        $token = $this->buyer1->createToken('b1')->plainTextToken;
        $body = ['offer_id' => $this->offer->id, 'pickup_slot_id' => $this->slot->id, 'quantity_kg' => '7.000', 'expected_price_per_kg' => 10000];

        DB::beginTransaction();
        User::whereKey($this->buyer1->id)->lockForUpdate()->first();
        $workers = [];
        for ($i = 1; $i <= 2; $i++) {
            $arg = base64_encode(json_encode(['uri' => '/api/v1/orders', 'method' => 'POST', 'token' => $token, 'idempotency_key' => 'same-retry-key-1', 'body' => $body, 'ip' => $i]));
            $workers[$i] = new Process([PHP_BINARY, __DIR__.'/http_worker.php', $arg], dirname(__DIR__, 2), $this->env, null, 60);
            $workers[$i]->start();
        }
        sleep(2);
        DB::rollBack();

        foreach ($workers as $p) { $p->wait(); $this->assertSame(201, (int) trim($p->getOutput())); }
        $this->assertSame(1, Order::count());
        $this->assertSame(7000, $this->offer->fresh()->reserved_g);
    }

    public function test_cancellation_and_expiry_do_not_release_stock_twice_on_mysql(): void
    {
        $token = $this->buyer1->createToken('b1')->plainTextToken;
        $order = app(\App\Services\OrderFlow::class)->checkout($this->buyer1->id, ['offer_id' => $this->offer->id, 'pickup_slot_id' => $this->slot->id, 'quantity_kg' => '7.000', 'expected_price_per_kg' => 10000], 'cancel-once-key');
        $order->update(['payment_due_at' => now()->subMinute()]);

        $arg = base64_encode(json_encode(['uri' => '/api/v1/orders/'.$order->id.'/cancel', 'method' => 'POST', 'token' => $token, 'body' => ['reason' => 'Batal paralel'], 'ip' => 1]));
        $p = new Process([PHP_BINARY, __DIR__.'/http_worker.php', $arg], dirname(__DIR__, 2), $this->env, null, 60);
        $p->run();
        $this->assertSame(200, (int) trim($p->getOutput()));

        // Expiry setelah cancel tidak boleh melepas stok kedua kalinya.
        app(Midtrans::class)->expire($order->fresh());
        $freshOffer = $this->offer->fresh();
        $this->assertSame(0, $freshOffer->reserved_g);
        $this->assertSame(7000, $freshOffer->withheld_g);
        $this->assertSame(10000, $freshOffer->stock_g);
    }

    public function test_confirmation_and_report_correction_do_not_duplicate_distribution_on_mysql(): void
    {
        $buyerToken = $this->buyer1->createToken('b1')->plainTextToken;
        $adminToken = $this->admin->createToken('adm')->plainTextToken;
        $order = app(\App\Services\OrderFlow::class)->checkout($this->buyer1->id, ['offer_id' => $this->offer->id, 'pickup_slot_id' => $this->slot->id, 'quantity_kg' => '7.000', 'expected_price_per_kg' => 10000], 'dist-once-key');
        $order->update(['status' => 'ready', 'payment_status' => 'paid']);

        // Dua konfirmasi penerimaan berulang hanya membuat 1 baris distribution.
        for ($i = 1; $i <= 2; $i++) {
            $arg = base64_encode(json_encode(['uri' => '/api/v1/orders/'.$order->id.'/receive', 'method' => 'POST', 'token' => $buyerToken, 'body' => ['received_kg' => '7.000'], 'ip' => $i]));
            $p = new Process([PHP_BINARY, __DIR__.'/http_worker.php', $arg], dirname(__DIR__, 2), $this->env, null, 60);
            $p->run();
            $this->assertSame(200, (int) trim($p->getOutput()));
        }
        $this->assertSame(1, Distribution::count());
        $this->assertSame(7000, $this->offer->fresh()->sold_g);

        // Dua koreksi laporan dengan key yang sama hanya memperbarui 1 ledger yang sama tanpa menggandakan penyaluran.
        $corrBody = ['received_kg' => '6.000', 'expected_received_kg' => '7.000', 'buyer_agreed' => true, 'reason' => 'Koreksi timbangan', 'agreement' => 'Disepakati 6 kg'];
        for ($i = 1; $i <= 2; $i++) {
            $arg = base64_encode(json_encode(['uri' => '/api/v1/admin/orders/'.$order->id.'/correct-distribution', 'method' => 'POST', 'token' => $adminToken, 'idempotency_key' => 'corr-mysql-1', 'body' => $corrBody, 'ip' => $i]));
            $p = new Process([PHP_BINARY, __DIR__.'/http_worker.php', $arg], dirname(__DIR__, 2), $this->env, null, 60);
            $p->run();
            $this->assertSame(200, (int) trim($p->getOutput()));
        }
        $this->assertSame(1, Distribution::count());
        $this->assertSame(6000, Distribution::first()->received_g);
        $this->assertSame(6000, $this->offer->fresh()->sold_g);
    }
}
