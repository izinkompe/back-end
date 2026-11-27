<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use App\Models\{User, Category, Address, Offer, PickupSlot, Order, Payment, Distribution, EmissionFactor, PriceStep};
use App\Services\{OrderFlow, Midtrans};

class TransactionTest extends TestCase
{
    use RefreshDatabase;
    private User $buyer;
    private User $farmer;
    private Offer $offer;
    private PickupSlot $slot;
    protected function setUp(): void
    {
        parent::setUp(); Http::preventStrayRequests();
        $this->buyer = User::create(['name' => 'Pembeli', 'email' => 'buyer@test.test', 'password' => 'Password123', 'role' => 'buyer']);
        $this->farmer = User::create(['name' => 'Petani', 'email' => 'farmer@test.test', 'password' => 'Password123', 'role' => 'farmer']);
        $this->buyer->forceFill(['email_verified_at' => now()])->save();
        $this->farmer->forceFill(['email_verified_at' => now()])->save();
        $cat = Category::create(['name' => 'Sayur']);
        $address = Address::create(['user_id' => $this->farmer->id, 'label' => 'Kebun', 'address' => 'Alamat rahasia', 'area' => 'Bogor', 'latitude' => -6.5, 'longitude' => 106.8]);
        $this->offer = Offer::create(['farmer_id' => $this->farmer->id, 'category_id' => $cat->id, 'address_id' => $address->id, 'name' => 'Bayam', 'condition' => 'Baik', 'stock_g' => 10000, 'price_per_kg' => 10000, 'minimum_price' => 5000, 'closes_at' => now()->addHours(2), 'status' => 'active', 'area' => 'Bogor', 'latitude' => -6.5, 'longitude' => 106.8]);
        $this->slot = PickupSlot::create(['offer_id' => $this->offer->id, 'starts_at' => now()->addHours(3), 'ends_at' => now()->addHours(4)]);
        Sanctum::actingAs($this->buyer);
    }
    private function body(string $quantity = '7.000'): array { return ['offer_id' => $this->offer->id, 'pickup_slot_id' => $this->slot->id, 'quantity_kg' => $quantity, 'expected_price_per_kg' => 10000]; }
    private function order(string $key = 'checkout-123'): Order { return app(OrderFlow::class)->checkout($this->buyer->id, $this->body(), $key); }
    private function gateway(Order $o, string $status, string $amount = '70000.00', string $fraud = 'accept'): void
    {
        Http::fake(['api.sandbox.midtrans.com/v2/*/status' => Http::response(['order_id' => $o->number, 'gross_amount' => $amount, 'transaction_status' => $status, 'fraud_status' => $fraud, 'transaction_id' => 'trx-1'])]);
    }
    public function test_checkout_retries_return_same_order_and_do_not_oversell(): void
    {
        $a = $this->postJson('/api/v1/orders', $this->body(), ['Idempotency-Key' => 'checkout-123'])->assertCreated();
        $this->postJson('/api/v1/orders', $this->body(), ['Idempotency-Key' => 'checkout-123'])->assertCreated()->assertJsonPath('id', $a->json('id'));
        $this->postJson('/api/v1/orders', $this->body(), ['Idempotency-Key' => 'checkout-456'])->assertConflict();
        $this->assertSame(7000, $this->offer->fresh()->reserved_g); $this->assertDatabaseCount('orders', 1);
    }
    public function test_key_reuse_with_different_payload_is_rejected(): void
    {
        $this->order(); $this->postJson('/api/v1/orders', $this->body('1.000'), ['Idempotency-Key' => 'checkout-123'])->assertConflict();
    }
    public function test_price_change_requires_new_consent(): void
    {
        PriceStep::create(['offer_id' => $this->offer->id, 'starts_at' => now()->subMinute(), 'price_per_kg' => 7500]);
        $this->postJson('/api/v1/orders', $this->body(), ['Idempotency-Key' => 'checkout-123'])->assertConflict();
        $this->assertDatabaseCount('orders', 0);
    }
    public function test_fractional_kg_snapshot_and_price_floor(): void
    {
        $body = $this->body('0.125'); $o = app(OrderFlow::class)->checkout($this->buyer->id, $body, 'checkout-123');
        $this->assertSame(1250, $o->total_idr); $this->offer->update(['name' => 'Berubah', 'price_per_kg' => 12000]);
        $this->assertSame('Bayam', $o->fresh()->product_snapshot['name']); $this->assertSame(10000, $o->fresh()->price_per_kg);
    }
    public function test_cancel_and_expiry_release_stock_only_once(): void
    {
        $o = $this->order();
        $this->postJson('/api/v1/orders/'.$o->id.'/cancel', ['reason' => 'Batal'])->assertOk();
        $this->postJson('/api/v1/orders/'.$o->id.'/cancel', ['reason' => 'Batal'])->assertOk();
        $this->assertSame(0, $this->offer->fresh()->reserved_g); $this->assertSame(10000, $this->offer->fresh()->stock_g);
    }
    public function test_uninitiated_expiry_needs_no_gateway_and_is_repeatable(): void
    {
        $o = $this->order(); $o->update(['payment_due_at' => now()->subMinute()]);
        app(Midtrans::class)->expire($o); app(Midtrans::class)->expire($o);
        $this->assertSame('cancelled', $o->fresh()->status); $this->assertSame(0, $this->offer->fresh()->reserved_g); Http::assertNothingSent();
    }
    public function test_404_does_not_release_a_snap_reservation(): void
    {
        $o = $this->order(); $o->payment->update(['status' => 'pending']);
        Http::fake(['*' => Http::response(['status_code' => '404'], 404)]);
        app(Midtrans::class)->reconcile($o); $this->assertSame(7000, $this->offer->fresh()->reserved_g);
    }
    public function test_payment_amount_mismatch_is_rejected(): void
    {
        $o = $this->order(); $this->gateway($o, 'settlement', '1.00');
        $this->postJson('/api/v1/orders/'.$o->id.'/sync-payment')->assertConflict();
        $this->assertSame('unpaid', $o->fresh()->payment_status);
    }
    public function test_capture_without_fraud_accept_does_not_mark_paid(): void
    {
        $o = $this->order(); $this->gateway($o, 'capture', '70000.00', 'challenge'); app(Midtrans::class)->reconcile($o);
        $this->assertSame('unpaid', $o->fresh()->payment_status);
    }
    public function test_forged_webhook_is_rejected_before_network_io(): void
    {
        $o = $this->order(); $this->postJson('/api/v1/payments/midtrans/notification', ['order_id' => $o->number, 'status_code' => '200', 'gross_amount' => '70000.00', 'signature_key' => str_repeat('0', 128)])->assertForbidden(); Http::assertNothingSent();
    }
    public function test_valid_duplicate_webhooks_use_server_status(): void
    {
        $o = $this->order(); $this->gateway($o, 'settlement');
        $payload = ['order_id' => $o->number, 'status_code' => '200', 'gross_amount' => '70000.00', 'signature_key' => hash('sha512', $o->number.'20070000.00'.config('pangantani.midtrans.server_key')), 'transaction_status' => 'pending'];
        $this->postJson('/api/v1/payments/midtrans/notification', $payload)->assertOk(); $this->postJson('/api/v1/payments/midtrans/notification', $payload)->assertOk();
        $this->assertSame('processing', $o->fresh()->status); $this->assertSame(7000, $this->offer->fresh()->reserved_g);
    }
    public function test_order_is_private_and_admin_cannot_register_publicly(): void
    {
        $o = $this->order(); $other = User::create(['name' => 'Lain', 'email' => 'other@test.test', 'password' => 'Password123', 'role' => 'buyer']); $other->forceFill(['email_verified_at' => now()])->save(); Sanctum::actingAs($other);
        $this->getJson('/api/v1/orders/'.$o->id)->assertForbidden();
        $this->postJson('/api/v1/auth/register', ['name' => 'Admin', 'email' => 'admin@test.test', 'password' => 'Password123', 'password_confirmation' => 'Password123', 'role' => 'admin'])->assertUnprocessable();
    }
    public function test_completion_records_distribution_once_with_factor_snapshot(): void
    {
        $o = $this->order(); $this->gateway($o, 'settlement'); app(Midtrans::class)->reconcile($o); $o->refresh()->update(['status' => 'ready']);
        EmissionFactor::create(['category_id' => $this->offer->category_id, 'kg_co2e_per_kg' => 2, 'source' => 'Sumber uji', 'version' => '1', 'method' => 'Berat x faktor', 'assumptions' => 'Asumsi uji']);
        $this->postJson('/api/v1/orders/'.$o->id.'/receive', ['received_kg' => '7.000'])->assertOk(); $this->postJson('/api/v1/orders/'.$o->id.'/receive', ['received_kg' => '7.000'])->assertOk();
        $this->assertDatabaseCount('distributions', 1); $this->assertSame(3000, $this->offer->fresh()->stock_g); $this->assertEquals(14, Distribution::first()->estimated_co2e_kg);
    }
    public function test_weight_mismatch_waits_for_admin_and_does_not_count_impact(): void
    {
        $o = $this->order(); $o->update(['status' => 'ready', 'payment_status' => 'paid']);
        $this->postJson('/api/v1/orders/'.$o->id.'/receive', ['received_kg' => '6.000'])->assertOk()->assertJsonPath('status', 'issue');
        $this->assertDatabaseCount('distributions', 0); $this->assertSame(7000, $this->offer->fresh()->reserved_g);
    }
    public function test_catalog_hides_full_pickup_address(): void
    {
        $this->getJson('/api/v1/offers/'.$this->offer->id)->assertOk()->assertJsonMissing(['address' => 'Alamat rahasia'])->assertJsonMissingPath('offer.address_id');
    }
    public function test_snap_is_created_once_and_reused(): void
    {
        $o = $this->order();
        Http::fake(['app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(['token' => 'snap-test', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-test'], 201)]);
        $this->postJson('/api/v1/orders/'.$o->id.'/payment')->assertOk()->assertJsonPath('snap_token', 'snap-test');
        $this->postJson('/api/v1/orders/'.$o->id.'/payment')->assertOk()->assertJsonPath('snap_token', 'snap-test');
        Http::assertSentCount(1); $this->assertSame(7000, $this->offer->fresh()->reserved_g);
    }
    public function test_snap_timeout_records_uncertainty_and_retains_stock(): void
    {
        $o = $this->order();
        Http::fake(function () { throw new \Illuminate\Http\Client\ConnectionException('Timeout'); });
        $this->postJson('/api/v1/orders/'.$o->id.'/payment')->assertStatus(502);
        $this->assertSame('uncertain', $o->payment()->first()->status);
        $this->postJson('/api/v1/orders/'.$o->id.'/payment')->assertConflict();
        $this->assertSame(7000, $this->offer->fresh()->reserved_g);
    }
    public function test_private_verification_evidence_cannot_be_read_by_a_buyer(): void
    {
        $verification = \App\Models\FarmerVerification::create(['user_id' => $this->farmer->id, 'description' => 'Bukti petani']);
        $media = \App\Models\Media::create(['user_id' => $this->farmer->id, 'attachable_type' => $verification->getMorphClass(), 'attachable_id' => $verification->id, 'kind' => 'verification', 'path' => 'private.png', 'mime' => 'image/png', 'size' => 100]);
        $this->getJson('/api/v1/media/'.$media->id)->assertForbidden();
    }
    public function test_pickup_offset_is_normalized_and_requires_buyer_approval(): void
    {
        $o = $this->order(); $o->update(['status' => 'processing', 'payment_status' => 'paid']);
        $start = now()->addDays(2)->startOfHour()->setTimezone('Asia/Jakarta'); $end = $start->copy()->addHours(2);
        $original = $o->pickup_starts_at->toIso8601String(); Sanctum::actingAs($this->farmer);
        $this->postJson('/api/v1/farmer/orders/'.$o->id.'/propose-pickup', ['starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String()])->assertOk();
        $this->assertSame($original, $o->fresh()->pickup_starts_at->toIso8601String());
        Sanctum::actingAs($this->buyer); $this->postJson('/api/v1/orders/'.$o->id.'/pickup-decision', ['accept' => true])->assertOk();
        $this->assertSame($start->copy()->utc()->format('Y-m-d H:i:s'), $o->fresh()->pickup_starts_at->format('Y-m-d H:i:s'));
    }
}
