<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Notification, URL};
use Laravel\Sanctum\Sanctum;
use App\Models\{User, Address, Category, Offer, PickupSlot, Order, Complaint, Media, Refund, Distribution, EmissionFactor, FarmerVerification};
use App\Services\OrderFlow;

class SrsComplianceTest extends TestCase
{
    use RefreshDatabase;
    private User $buyer;
    private User $farmer;
    private User $admin;
    private Offer $offer;
    private PickupSlot $slot;

    protected function setUp(): void
    {
        parent::setUp(); Http::preventStrayRequests(); Notification::fake();
        $this->buyer = User::create(['name' => 'Pembeli', 'email' => 'buyer@srs.test', 'role' => 'buyer', 'password' => 'Password123']);
        $this->farmer = User::create(['name' => 'Petani', 'email' => 'farmer@srs.test', 'role' => 'farmer', 'password' => 'Password123']);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@srs.test', 'role' => 'admin', 'password' => 'Password123']);
        $category = Category::create(['name' => 'Sayuran']);
        $address = Address::create(['user_id' => $this->farmer->id, 'label' => 'Kebun', 'address' => 'Alamat privat', 'area' => 'Bogor', 'latitude' => -6.5, 'longitude' => 106.8]);
        $this->offer = Offer::create(['farmer_id' => $this->farmer->id, 'category_id' => $category->id, 'address_id' => $address->id, 'name' => 'Bayam surplus', 'commodity' => 'Bayam', 'condition' => 'Baik', 'stock_g' => 10000, 'minimum_price' => 5000, 'price_per_kg' => 10000, 'closes_at' => now()->addHours(2), 'status' => 'active', 'area' => 'Bogor', 'latitude' => -6.5, 'longitude' => 106.8]);
        $this->slot = PickupSlot::create(['offer_id' => $this->offer->id, 'starts_at' => now()->addHours(3), 'ends_at' => now()->addHours(4)]);
        Sanctum::actingAs($this->buyer);
    }
    private function order(): Order
    {
        return app(OrderFlow::class)->checkout($this->buyer->id, ['offer_id' => $this->offer->id, 'pickup_slot_id' => $this->slot->id, 'quantity_kg' => '7.000', 'expected_price_per_kg' => 10000], 'srs-order-123');
    }
    private function completed(): Order
    {
        $o = $this->order(); $o->update(['status' => 'ready', 'payment_status' => 'paid']);
        $this->postJson('/api/v1/orders/'.$o->id.'/receive', ['received_kg' => '7.000'])->assertOk(); return $o->fresh();
    }
    public function test_stock_shortage_is_recorded_and_opens_handling_automatically(): void
    {
        $o = $this->order(); Sanctum::actingAs($this->farmer);
        $this->patchJson('/api/v1/farmer/offers/'.$this->offer->id.'/inventory', ['stock_kg' => '6.000', 'reason' => 'Sebagian panen rusak'])->assertOk()->assertJsonPath('stock_g', 6000)->assertJsonPath('shortage_g', 1000)->assertJsonPath('available_g', 0);
        $this->assertSame('issue', $o->fresh()->status); $this->assertSame('closed', $this->offer->fresh()->status);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->buyer->id, 'link' => '/orders/'.$o->id]);
    }
    public function test_released_stock_waits_for_farmer_suitability_declaration(): void
    {
        $o = $this->order(); $this->postJson('/api/v1/orders/'.$o->id.'/cancel', ['reason' => 'Batal'])->assertOk();
        $this->assertSame(7000, $this->offer->fresh()->withheld_g); $this->assertSame(3000, $this->offer->fresh()->available_g);
        Sanctum::actingAs($this->farmer);
        $this->postJson('/api/v1/farmer/offers/'.$this->offer->id.'/confirm-resale', ['fit_for_sale' => true, 'reason' => 'Sudah diperiksa, kondisi tetap baik'])->assertOk();
        $this->assertSame(10000, $this->offer->fresh()->available_g);
    }
    public function test_admin_cannot_complete_without_actual_buyer_receipt(): void
    {
        $o = $this->order(); $o->update(['status' => 'issue', 'payment_status' => 'paid']); Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/admin/orders/'.$o->id.'/resolve', ['action' => 'complete', 'received_kg' => '7.000', 'buyer_agreed' => true, 'agreement' => 'Kesepakatan', 'reason' => 'Penyelesaian'])->assertConflict();
        $this->assertDatabaseCount('distributions', 0);
    }
    public function test_shortage_can_be_settled_without_negative_stock_after_buyer_receipt(): void
    {
        $o = $this->order(); $o->update(['payment_status' => 'paid']); Sanctum::actingAs($this->farmer);
        $this->patchJson('/api/v1/farmer/offers/'.$this->offer->id.'/inventory', ['stock_kg' => '6.000', 'reason' => 'Rusak sebagian'])->assertOk();
        Sanctum::actingAs($this->buyer); $this->postJson('/api/v1/orders/'.$o->id.'/receive', ['received_kg' => '6.000'])->assertOk()->assertJsonPath('status', 'issue');
        Sanctum::actingAs($this->admin); $this->postJson('/api/v1/admin/orders/'.$o->id.'/resolve', ['action' => 'complete', 'received_kg' => '6.000', 'buyer_agreed' => true, 'agreement' => 'Pembeli menyepakati 6 kg dan penanganan selisih.', 'reason' => 'Selisih diselesaikan'])->assertOk();
        $this->assertSame(0, $this->offer->fresh()->stock_g); $this->assertSame(0, $this->offer->fresh()->reserved_g); $this->assertDatabaseHas('distributions', ['order_id' => $o->id, 'received_g' => 6000]);
    }
    public function test_completed_weight_correction_updates_one_ledger_and_is_idempotent(): void
    {
        EmissionFactor::create(['category_id' => $this->offer->category_id, 'kg_co2e_per_kg' => 2, 'source' => 'Sumber uji', 'version' => '1', 'method' => 'Berat x faktor', 'assumptions' => 'Uji']);
        $o = $this->completed(); Sanctum::actingAs($this->admin);
        $v = ['received_kg' => '6.000', 'expected_received_kg' => '7.000', 'buyer_agreed' => true, 'reason' => 'Koreksi timbangan', 'agreement' => 'Kedua pihak menyepakati 6 kg'];
        $url = '/api/v1/admin/orders/'.$o->id.'/correct-distribution';
        $this->postJson($url, $v, ['Idempotency-Key' => 'correction-123'])->assertOk();
        $this->postJson($url, $v, ['Idempotency-Key' => 'correction-123'])->assertOk();
        $this->postJson($url, $v, ['Idempotency-Key' => 'correction-456'])->assertConflict();
        $this->assertDatabaseCount('distributions', 1); $this->assertDatabaseCount('distribution_corrections', 1);
        $this->assertEquals(12, Distribution::first()->estimated_co2e_kg); $this->assertSame(6000, $this->offer->fresh()->sold_g);
        $this->getJson('/api/v1/admin/reports')->assertOk()->assertJsonPath('summary.distributed_g', 6000);
    }
    public function test_only_admin_may_correct_a_completed_distribution(): void
    {
        $o = $this->completed(); $this->postJson('/api/v1/admin/orders/'.$o->id.'/correct-distribution', [])->assertForbidden();
    }
    public function test_admin_filters_by_complaint_type_and_parties_can_clarify(): void
    {
        $o = $this->order(); $c = $this->postJson('/api/v1/orders/'.$o->id.'/complaints', ['type' => 'weight', 'description' => 'Berat kurang'])->assertCreated()->json('id');
        Sanctum::actingAs($this->admin);
        $this->patchJson('/api/v1/admin/complaints/'.$c, ['status' => 'clarification', 'response' => 'Mohon hasil timbang'])->assertOk();
        $this->getJson('/api/v1/admin/complaints?type=payment')->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($this->farmer); $this->postJson('/api/v1/complaints/'.$c.'/messages', ['message' => 'Hasil timbang sudah dicek'])->assertCreated();
        Sanctum::actingAs($this->buyer); $this->getJson('/api/v1/complaints/'.$c)->assertOk()->assertJsonCount(2, 'messages');
    }
    public function test_farmer_approval_requires_private_evidence(): void
    {
        $v = FarmerVerification::create(['user_id' => $this->farmer->id, 'description' => 'Kebun']); Sanctum::actingAs($this->admin);
        $this->patchJson('/api/v1/admin/verifications/'.$v->id, ['status' => 'approved', 'reason' => 'Ditinjau'])->assertUnprocessable();
        Media::create(['user_id' => $this->farmer->id, 'attachable_type' => FarmerVerification::class, 'attachable_id' => $v->id, 'kind' => 'verification', 'path' => 'evidence.png', 'mime' => 'image/png', 'size' => 100]);
        $this->patchJson('/api/v1/admin/verifications/'.$v->id, ['status' => 'approved', 'reason' => 'Bukti sesuai'])->assertOk();
    }
    public function test_refund_completion_requires_reference_and_evidence(): void
    {
        $o = $this->order(); $o->update(['payment_status' => 'paid']); Sanctum::actingAs($this->admin);
        $url = '/api/v1/admin/orders/'.$o->id.'/refund';
        $v = ['amount_idr' => 10000, 'status' => 'completed', 'reason' => 'Selisih', 'reference' => 'REF-123'];
        $this->putJson($url, $v)->assertUnprocessable();
        $this->putJson($url, array_replace($v, ['status' => 'processing']))->assertOk(); $refund = Refund::firstOrFail();
        Media::create(['user_id' => $this->admin->id, 'attachable_type' => Refund::class, 'attachable_id' => $refund->id, 'kind' => 'refund', 'path' => 'refund.png', 'mime' => 'image/png', 'size' => 100]);
        $this->putJson($url, $v)->assertOk(); $this->assertSame('completed', $refund->fresh()->status);
    }
    public function test_unverified_email_cannot_receive_password_reset_but_can_be_verified(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $this->buyer->email])->assertOk();
        Notification::assertNothingSent();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $this->buyer->id, 'hash' => sha1($this->buyer->email)]);
        $this->getJson($url)->assertOk(); $this->assertNotNull($this->buyer->fresh()->email_verified_at);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $this->buyer->email])->assertOk();
        Notification::assertSentTo($this->buyer, \Illuminate\Auth\Notifications\ResetPassword::class);
    }
    public function test_email_changes_require_password_and_clear_verification(): void
    {
        $this->buyer->forceFill(['email_verified_at' => now()])->save();
        $this->patchJson('/api/v1/me', ['email' => 'new@srs.test', 'current_password' => 'wrong'])->assertUnprocessable();
        $this->patchJson('/api/v1/me', ['email' => 'new@srs.test', 'current_password' => 'Password123'])->assertOk()->assertJsonPath('email', 'new@srs.test')->assertJsonPath('email_verified_at', null);
    }
    public function test_draft_can_be_incomplete_and_cannot_be_published_early(): void
    {
        Sanctum::actingAs($this->farmer); $draft = $this->postJson('/api/v1/farmer/offers', ['name' => 'Draf', 'slots' => []])->assertCreated()->json('id');
        $this->postJson('/api/v1/farmer/offers/'.$draft.'/publish')->assertForbidden();
        $this->assertDatabaseHas('offers', ['id' => $draft, 'status' => 'draft', 'stock_g' => 0]);
    }
    public function test_catalog_has_empty_state_and_filters_effective_price_and_stock(): void
    {
        $this->getJson('/api/v1/offers?q=tidak-ada')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('clear_filters_url', url('/api/v1/offers'));
        $this->getJson('/api/v1/offers?max_price=9000')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/offers?min_stock_kg=9')->assertOk()->assertJsonPath('total', 1);
    }
    public function test_factors_with_wrong_units_or_methods_are_rejected(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/admin/emission-factors', ['category_id' => $this->offer->category_id, 'kg_co2e_per_kg' => 2, 'unit' => 'tonCO2e/kg', 'method_code' => 'unsupported', 'source' => 'Sumber', 'version' => '1', 'method' => 'Uji', 'assumptions' => 'Uji'])->assertUnprocessable();
    }
    public function test_password_reset_is_single_use_and_revokes_existing_tokens(): void
    {
        $this->buyer->forceFill(['email_verified_at' => now()])->save();
        $this->buyer->createToken('old-session');
        $token = \Illuminate\Support\Facades\Password::createToken($this->buyer);
        $v = ['email' => $this->buyer->email, 'token' => $token, 'password' => 'Changed12345', 'password_confirmation' => 'Changed12345'];
        $this->postJson('/api/v1/auth/reset-password', $v)->assertOk();
        $this->postJson('/api/v1/auth/reset-password', $v)->assertUnprocessable();
        $this->assertSame(0, $this->buyer->tokens()->count());
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Changed12345', $this->buyer->fresh()->password));
    }
    public function test_expired_reset_and_verification_links_are_rejected(): void
    {
        $this->buyer->forceFill(['email_verified_at' => now()])->save();
        $token = \Illuminate\Support\Facades\Password::createToken($this->buyer);
        \Illuminate\Support\Facades\DB::table('password_reset_tokens')->where('email', $this->buyer->email)->update(['created_at' => now()->subHours(2)]);
        $this->postJson('/api/v1/auth/reset-password', ['email' => $this->buyer->email, 'token' => $token, 'password' => 'Changed12345', 'password_confirmation' => 'Changed12345'])->assertUnprocessable();
        $url = URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['id' => $this->buyer->id, 'hash' => sha1($this->buyer->email)]);
        $this->getJson($url)->assertForbidden();
    }
    public function test_closed_share_link_survives_and_never_exposes_a_private_address(): void
    {
        $o = $this->order(); Sanctum::actingAs($this->farmer);
        $this->postJson('/api/v1/farmer/offers/'.$this->offer->id.'/close', ['reason' => 'Tutup untuk pesanan baru'])->assertOk();
        $this->getJson('/api/v1/offers/'.$this->offer->id)->assertOk()->assertJsonPath('offer.effective_status', 'closed')->assertJsonMissing(['address' => 'Alamat privat']);
        $this->assertSame('awaiting_payment', $o->fresh()->status);
        $this->getJson('/api/v1/offers')->assertOk()->assertJsonPath('total', 0);
    }
    public function test_favorite_commodity_reminder_is_sent_once_per_user_and_offer(): void
    {
        \App\Models\Favorite::create(['user_id' => $this->buyer->id, 'kind' => 'commodity', 'value' => 'bayam']);
        $this->buyer->update(['preferences' => ['reminders' => true, 'radius_km' => 10, 'latitude' => -6.5, 'longitude' => 106.8]]);
        \App\Services\Reminders::forOffer($this->offer); \App\Services\Reminders::forOffer($this->offer);
        $this->assertSame(1, \App\Models\Notice::where('user_id', $this->buyer->id)->where('event_key', 'offer:'.$this->offer->id)->count());
    }
    public function test_need_selection_rechecks_available_stock_and_active_pickup_slot(): void
    {
        $need = \App\Models\FoodNeed::create(['buyer_id' => $this->buyer->id, 'commodity' => 'Bayam', 'quantity_g' => 10000, 'area' => 'Bogor', 'needed_at' => now()->addDay()]);
        Sanctum::actingAs($this->farmer);
        $id = $this->postJson('/api/v1/farmer/needs/'.$need->id.'/responses', ['offer_id' => $this->offer->id, 'pickup_slot_id' => $this->slot->id, 'quantity_kg' => '7.000'])->assertCreated()->json('id');
        Sanctum::actingAs($this->buyer); $url = '/api/v1/needs/'.$need->id.'/responses/'.$id.'/select';
        $this->postJson($url)->assertOk()->assertJsonPath('checkout.expected_price_per_kg', 10000);
        $this->assertDatabaseCount('orders', 0);
        $this->offer->update(['stock_g' => 5000]); $this->postJson($url)->assertConflict();
    }
    public function test_missing_or_expired_emission_factor_is_not_reported_as_zero(): void
    {
        EmissionFactor::create(['category_id' => $this->offer->category_id, 'kg_co2e_per_kg' => 2, 'source' => 'Sumber', 'version' => '1', 'method' => 'Berat x faktor', 'assumptions' => 'Uji', 'valid_until' => now()->subDay()]);
        $this->completed(); $this->assertNull(Distribution::first()->estimated_co2e_kg);
        Sanctum::actingAs($this->admin); $this->getJson('/api/v1/admin/reports')->assertOk()->assertJsonPath('summary.uncalculated_orders', 1);
    }
    public function test_only_the_owner_can_change_addresses_and_notification_read_state(): void
    {
        $a = Address::where('user_id', $this->farmer->id)->firstOrFail();
        $this->putJson('/api/v1/addresses/'.$a->id, [])->assertForbidden();
        $notice = \App\Models\Notice::create(['user_id' => $this->farmer->id, 'title' => 'Privat', 'link' => '/profile']);
        $this->patchJson('/api/v1/notifications/'.$notice->id.'/read')->assertForbidden();
        $this->patchJson('/api/v1/me', ['preferences' => ['high_contrast' => true, 'text_scale' => 2]])->assertOk();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.preferences.high_contrast', true)->assertJsonPath('user.preferences.text_scale', 2);
    }
    public function test_csv_scope_and_filters_match_reported_data_and_escape_formulas(): void
    {
        $this->offer->update(['name' => '=FORMULA', 'commodity' => '=FORMULA']); $this->completed(); Sanctum::actingAs($this->admin);
        $csv = $this->getJson('/api/v1/admin/reports/export?area=Bogor')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=FORMULA", $csv); $this->assertStringContainsString('7.000', $csv);
        $filtered = $this->getJson('/api/v1/admin/reports/export?area=Jakarta')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('FORMULA', $filtered);
        $all = $this->getJson('/api/v1/admin/reports/export?scope=orders')->assertOk()->streamedContent();
        $this->assertStringContainsString('orders', $all); $this->assertStringContainsString('completed', $all);
    }
    public function test_price_preview_and_duplicate_preserve_basic_data_but_reset_harvest(): void
    {
        Media::create(['user_id' => $this->farmer->id, 'attachable_type' => Offer::class, 'attachable_id' => $this->offer->id, 'kind' => 'offer_photo', 'path' => 'public.png', 'mime' => 'image/png', 'size' => 100]);
        Sanctum::actingAs($this->farmer);
        $this->getJson('/api/v1/farmer/offers/'.$this->offer->id.'/price-preview')->assertOk()->assertJsonPath('current_price', 10000);
        $copy = $this->postJson('/api/v1/farmer/offers/'.$this->offer->id.'/duplicate')->assertCreated()->json('id');
        $this->assertDatabaseHas('offers', ['id' => $copy, 'stock_g' => 0, 'condition' => null, 'harvest_date' => null, 'closes_at' => null, 'status' => 'draft']);
        $this->assertSame(1, Media::where('attachable_type', Offer::class)->where('attachable_id', $copy)->count());
    }
    public function test_upgrade_retains_existing_records_and_requires_review_of_legacy_releases(): void
    {
        $o = $this->order(); $this->postJson('/api/v1/orders/'.$o->id.'/cancel', ['reason' => 'Batal'])->assertOk();
        // Simulate the installed v1 schema using only the isolated in-memory test connection.
        $upgrade = require __DIR__.'/../../database/migrations/2026_11_22_000002_align_srs_workflows.php';
        $upgrade->down(); $upgrade->up();
        $this->assertDatabaseCount('users', 3); $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('orders', ['id' => $o->id, 'status' => 'cancelled']);
        $this->assertSame(10000, $this->offer->fresh()->withheld_g);
        $this->assertSame(0, $this->offer->fresh()->available_g);
    }
    public function test_verified_farmer_to_midtrans_payment_pickup_and_distribution_end_to_end(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $image = fn () => \Illuminate\Http\UploadedFile::fake()->createWithContent('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aVS0AAAAASUVORK5CYII='));
        Sanctum::actingAs($this->farmer);
        $verification = $this->postJson('/api/v1/farmer/verification', ['description' => 'Kebun keluarga'])->assertCreated()->json('id');
        $this->post('/api/v1/media', ['kind' => 'verification', 'target_id' => $verification, 'file' => $image()], ['Accept' => 'application/json'])->assertCreated();
        Sanctum::actingAs($this->admin);
        $this->patchJson('/api/v1/admin/verifications/'.$verification, ['status' => 'approved', 'reason' => 'Bukti sesuai'])->assertOk();
        Sanctum::actingAs($this->farmer);
        $draft = $this->postJson('/api/v1/farmer/offers', ['name' => 'Kangkung', 'commodity' => 'Kangkung', 'category_id' => $this->offer->category_id, 'address_id' => $this->offer->getRawOriginal('address_id'), 'surplus_reason' => 'Surplus panen', 'condition' => 'Baik', 'harvest_date' => now()->toDateString(), 'stock_kg' => '5.000', 'minimum_price' => 5000, 'price_per_kg' => 10000, 'closes_at' => now()->addHours(2)->toIso8601String(), 'slots' => [['starts_at' => now()->addHours(3)->toIso8601String(), 'ends_at' => now()->addHours(4)->toIso8601String()]]])->assertCreated()->json();
        $this->post('/api/v1/media', ['kind' => 'offer_photo', 'target_id' => $draft['id'], 'file' => $image()], ['Accept' => 'application/json'])->assertCreated();
        $this->postJson('/api/v1/farmer/offers/'.$draft['id'].'/publish')->assertOk()->assertJsonPath('status', 'active');
        Sanctum::actingAs($this->buyer);
        $o = $this->postJson('/api/v1/orders', ['offer_id' => $draft['id'], 'pickup_slot_id' => $draft['slots'][0]['id'], 'quantity_kg' => '2.000', 'expected_price_per_kg' => 10000], ['Idempotency-Key' => 'srs-e2e-checkout'])->assertCreated()->json();
        Http::fake([
            'app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(['token' => 'snap-e2e', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-e2e'], 201),
            'api.sandbox.midtrans.com/v2/*/status' => Http::response(['order_id' => $o['number'], 'gross_amount' => '20000.00', 'transaction_status' => 'settlement', 'transaction_id' => 'trx-e2e']),
        ]);
        $this->postJson('/api/v1/orders/'.$o['id'].'/payment')->assertOk();
        $this->postJson('/api/v1/orders/'.$o['id'].'/sync-payment')->assertOk()->assertJsonPath('status', 'processing');
        Sanctum::actingAs($this->farmer); $this->postJson('/api/v1/farmer/orders/'.$o['id'].'/ready')->assertOk();
        Sanctum::actingAs($this->buyer); $this->postJson('/api/v1/orders/'.$o['id'].'/receive', ['received_kg' => '2.000'])->assertOk()->assertJsonPath('status', 'completed');
        Sanctum::actingAs($this->admin); $this->getJson('/api/v1/admin/reports')->assertOk()->assertJsonPath('summary.distributed_g', 2000);
    }
    public function test_radius_uses_straight_line_distance_and_hides_outside_offers(): void
    {
        $this->getJson('/api/v1/offers?latitude=-6.5&longitude=106.8&radius_km=1&sort=distance')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('distance_type', 'straight_line');
        $this->getJson('/api/v1/offers?latitude=0&longitude=0&radius_km=1')->assertOk()->assertJsonPath('total', 0);
    }
    public function test_harvest_date_uses_jakarta_calendar_and_invalid_dates_are_not_normalized(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-23T00:30:00+07:00'));
        Sanctum::actingAs($this->farmer);
        $this->postJson('/api/v1/farmer/offers', ['name' => 'Panen hari ini', 'harvest_date' => '2026-11-23'])->assertCreated();
        $this->postJson('/api/v1/farmer/offers', ['name' => 'Panen besok', 'harvest_date' => '2026-11-24'])->assertUnprocessable();
        $this->postJson('/api/v1/farmer/offers', ['name' => 'Tanggal tidak sah', 'closes_at' => '2027-02-31T12:00:00Z'])->assertUnprocessable();
    }
    public function test_farmer_cannot_override_admin_moderation_or_release_it(): void
    {
        $o = $this->order(); Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/admin/offers/'.$this->offer->id.'/close', ['reason' => 'Perlu klarifikasi kondisi'])->assertOk();
        Sanctum::actingAs($this->farmer);
        $this->postJson('/api/v1/farmer/offers/'.$this->offer->id.'/publish')->assertForbidden();
        $this->postJson('/api/v1/admin/offers/'.$this->offer->id.'/release-moderation', ['reason' => 'Coba sendiri'])->assertForbidden();
        $this->getJson('/api/v1/farmer/offers')->assertOk()->assertJsonPath('data.0.moderation_reason', 'Perlu klarifikasi kondisi');
        $this->assertSame('awaiting_payment', $o->fresh()->status);
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/admin/offers/'.$this->offer->id.'/release-moderation', ['reason' => 'Klarifikasi selesai'])->assertOk();
        $this->assertNull($this->offer->fresh()->moderated_at); $this->assertSame('closed', $this->offer->fresh()->status);
    }
    public function test_production_requires_https_and_only_honors_known_proxies(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->getJson('/api/v1/categories')->assertStatus(400);
        $this->withHeaders(['X-Forwarded-Proto' => 'https'])->getJson('/api/v1/categories')->assertStatus(400);
        config(['pangantani.trusted_proxies' => ['10.0.0.1']]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->getJson('/api/v1/categories')->assertOk();
    }
    public function test_upload_rejects_disguised_non_image_content(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local'); Sanctum::actingAs($this->farmer);
        $fake = \Illuminate\Http\UploadedFile::fake()->createWithContent('photo.png', '<html>not an image</html>');
        $this->post('/api/v1/media', ['kind' => 'offer_photo', 'target_id' => $this->offer->id, 'file' => $fake], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(0, Media::count());
    }
}
