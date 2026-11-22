<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable();
            $t->string('password'); $t->string('role', 20)->index(); $t->string('phone', 30)->nullable();
            $t->string('organization')->nullable(); $t->json('preferences')->nullable(); $t->rememberToken(); $t->timestamps();
        });
        Schema::create('password_reset_tokens', function (Blueprint $t) { $t->string('email')->primary(); $t->string('token'); $t->timestamp('created_at')->nullable(); });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id(); $t->morphs('tokenable'); $t->string('name'); $t->string('token', 64)->unique(); $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable(); $t->timestamp('expires_at')->nullable()->index(); $t->timestamps();
        });
        Schema::create('addresses', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained(); $t->string('label'); $t->text('address'); $t->string('area');
            $t->decimal('latitude', 10, 7); $t->decimal('longitude', 10, 7); $t->text('instructions')->nullable(); $t->timestamps();
        });
        Schema::create('farmer_verifications', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->unique()->constrained(); $t->text('description'); $t->string('status', 30)->default('pending')->index();
            $t->text('reason')->nullable(); $t->foreignId('reviewer_id')->nullable()->constrained('users'); $t->timestamp('reviewed_at')->nullable(); $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->timestamps(); });
        Schema::create('offers', function (Blueprint $t) {
            $t->id(); $t->foreignId('farmer_id')->constrained('users'); $t->foreignId('category_id')->nullable()->constrained(); $t->foreignId('address_id')->nullable()->constrained();
            $t->string('name'); $t->text('description')->nullable(); $t->text('surplus_reason')->nullable(); $t->text('condition')->nullable();
            $t->date('harvest_date')->nullable(); $t->unsignedBigInteger('stock_g')->default(0); $t->unsignedBigInteger('reserved_g')->default(0); $t->unsignedBigInteger('sold_g')->default(0);
            $t->unsignedInteger('price_per_kg')->default(0); $t->unsignedInteger('minimum_price')->default(0);
            $t->timestamp('closes_at')->nullable()->index(); $t->string('status', 20)->default('draft')->index(); $t->string('area')->nullable();
            $t->decimal('latitude', 10, 7)->nullable(); $t->decimal('longitude', 10, 7)->nullable(); $t->timestamps(); $t->index(['category_id', 'status']);
        });
        Schema::create('price_steps', function (Blueprint $t) { $t->id(); $t->foreignId('offer_id')->constrained(); $t->dateTime('starts_at'); $t->unsignedInteger('price_per_kg'); $t->unique(['offer_id', 'starts_at']); $t->timestamps(); });
        Schema::create('pickup_slots', function (Blueprint $t) { $t->id(); $t->foreignId('offer_id')->constrained(); $t->dateTime('starts_at'); $t->dateTime('ends_at'); $t->boolean('active')->default(true); $t->timestamps(); });
        Schema::create('orders', function (Blueprint $t) {
            $t->id(); $t->string('number', 50)->unique(); $t->foreignId('buyer_id')->constrained('users'); $t->foreignId('farmer_id')->constrained('users'); $t->foreignId('offer_id')->constrained();
            $t->string('idempotency_key', 100); $t->string('request_hash', 64); $t->unsignedBigInteger('quantity_g'); $t->unsignedInteger('price_per_kg'); $t->unsignedBigInteger('total_idr');
            $t->json('product_snapshot'); $t->json('pickup_snapshot'); $t->json('proposed_pickup')->nullable(); $t->dateTime('pickup_starts_at'); $t->dateTime('pickup_ends_at');
            $t->dateTime('payment_due_at')->index(); $t->string('status', 30)->default('awaiting_payment')->index(); $t->string('payment_status', 30)->default('unpaid');
            $t->string('stock_state', 20)->default('reserved'); $t->unsignedBigInteger('received_g')->nullable(); $t->timestamp('completed_at')->nullable(); $t->text('issue_reason')->nullable();
            $t->timestamps(); $t->unique(['buyer_id', 'idempotency_key']);
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id(); $t->foreignId('order_id')->unique()->constrained(); $t->string('gateway_order_id', 50)->unique();
            $t->string('status', 30)->default('not_started'); $t->string('snap_token')->nullable(); $t->text('redirect_url')->nullable();
            $t->string('transaction_id')->nullable(); $t->timestamp('initiated_at')->nullable(); $t->timestamp('checked_at')->nullable(); $t->timestamps();
        });
        Schema::create('complaints', function (Blueprint $t) {
            $t->id(); $t->foreignId('order_id')->constrained(); $t->foreignId('user_id')->constrained(); $t->text('description');
            $t->string('status', 30)->default('open')->index(); $t->text('response')->nullable(); $t->timestamps();
        });
        Schema::create('refunds', function (Blueprint $t) {
            $t->id(); $t->foreignId('order_id')->unique()->constrained(); $t->unsignedBigInteger('amount_idr'); $t->string('status', 20)->default('requested');
            $t->text('reason'); $t->string('reference')->nullable(); $t->foreignId('recorded_by')->constrained('users'); $t->timestamps();
        });
        Schema::create('media', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained(); $t->nullableMorphs('attachable'); $t->string('kind', 30); $t->string('path');
            $t->string('mime'); $t->unsignedInteger('size'); $t->timestamps();
        });
        Schema::create('notifications', function (Blueprint $t) { $t->id(); $t->foreignId('user_id')->constrained(); $t->string('event_key')->nullable(); $t->string('title'); $t->string('link'); $t->timestamp('read_at')->nullable(); $t->timestamps(); $t->unique(['user_id', 'event_key']); });
        Schema::create('favorites', function (Blueprint $t) { $t->id(); $t->foreignId('user_id')->constrained(); $t->string('kind', 20); $t->string('value'); $t->timestamps(); $t->unique(['user_id', 'kind', 'value']); });
        Schema::create('food_needs', function (Blueprint $t) { $t->id(); $t->foreignId('buyer_id')->constrained('users'); $t->string('commodity'); $t->unsignedBigInteger('quantity_g'); $t->string('area'); $t->dateTime('needed_at')->index(); $t->string('status', 20)->default('active'); $t->timestamps(); });
        Schema::create('need_responses', function (Blueprint $t) { $t->id(); $t->foreignId('food_need_id')->constrained(); $t->foreignId('farmer_id')->constrained('users'); $t->foreignId('offer_id')->constrained(); $t->foreignId('pickup_slot_id')->constrained(); $t->unsignedBigInteger('quantity_g'); $t->unsignedInteger('price_per_kg'); $t->text('message')->nullable(); $t->timestamps(); });
        Schema::create('emission_factors', function (Blueprint $t) { $t->id(); $t->foreignId('category_id')->constrained(); $t->decimal('kg_co2e_per_kg', 12, 6); $t->string('unit')->default('kgCO2e/kg'); $t->text('source'); $t->string('version'); $t->text('method'); $t->text('assumptions'); $t->boolean('active')->default(true); $t->timestamps(); });
        Schema::create('distributions', function (Blueprint $t) { $t->id(); $t->foreignId('order_id')->unique()->constrained(); $t->unsignedBigInteger('received_g'); $t->foreignId('emission_factor_id')->nullable()->constrained(); $t->decimal('estimated_co2e_kg', 18, 6)->nullable(); $t->json('factor_snapshot')->nullable(); $t->timestamps(); });
        Schema::create('audit_logs', function (Blueprint $t) { $t->id(); $t->foreignId('actor_id')->nullable()->constrained('users'); $t->string('action'); $t->string('subject_type'); $t->unsignedBigInteger('subject_id'); $t->json('before')->nullable(); $t->json('after')->nullable(); $t->text('reason')->nullable(); $t->timestamps(); $t->index(['subject_type', 'subject_id']); });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'distributions', 'emission_factors', 'need_responses', 'food_needs', 'favorites', 'notifications', 'media', 'refunds', 'complaints', 'payments', 'orders', 'pickup_slots', 'price_steps', 'offers', 'categories', 'farmer_verifications', 'addresses', 'personal_access_tokens', 'password_reset_tokens', 'users'] as $table) { Schema::dropIfExists($table); }
    }
};
