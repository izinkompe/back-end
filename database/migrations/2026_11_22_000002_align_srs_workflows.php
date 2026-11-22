<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->unsignedBigInteger('withheld_g')->default(0); $t->string('commodity', 150)->nullable()->index();
            $t->dateTime('moderated_at')->nullable(); $t->text('moderation_reason')->nullable();
        });
        Schema::table('orders', function (Blueprint $t) { $t->dateTime('buyer_confirmed_at')->nullable(); $t->text('resolution_agreement')->nullable(); });
        Schema::table('complaints', function (Blueprint $t) { $t->string('type', 30)->default('other')->index(); });
        Schema::table('payments', function (Blueprint $t) { $t->text('status_message')->nullable(); $t->string('fraud_status', 30)->nullable(); });
        Schema::table('emission_factors', function (Blueprint $t) {
            $t->string('method_code', 50)->default('weight_factor_v1');
            $t->dateTime('valid_from')->nullable(); $t->dateTime('valid_until')->nullable();
        });
        Schema::create('distribution_corrections', function (Blueprint $t) {
            $t->id(); $t->foreignId('order_id')->constrained(); $t->foreignId('recorded_by')->constrained('users');
            $t->string('idempotency_key', 100); $t->string('request_hash', 64);
            $t->unsignedBigInteger('old_received_g'); $t->unsignedBigInteger('new_received_g');
            $t->text('reason'); $t->text('agreement'); $t->timestamps(); $t->unique(['order_id', 'idempotency_key']);
        });
        Schema::create('complaint_messages', function (Blueprint $t) {
            $t->id(); $t->foreignId('complaint_id')->constrained(); $t->foreignId('user_id')->constrained();
            $t->text('message'); $t->timestamps();
        });
        // Legacy releases predate the farmer suitability workflow. Require a review before resale.
        \Illuminate\Support\Facades\DB::table('offers')->whereExists(function ($q) {
            $q->selectRaw('1')->from('orders')->whereColumn('orders.offer_id', 'offers.id')->where('orders.stock_state', 'released');
        })->update(['withheld_g' => \Illuminate\Support\Facades\DB::raw('stock_g')]);
    }
    public function down(): void
    {
        Schema::dropIfExists('complaint_messages'); Schema::dropIfExists('distribution_corrections');
        Schema::table('emission_factors', fn (Blueprint $t) => $t->dropColumn(['method_code', 'valid_from', 'valid_until']));
        Schema::table('complaints', function (Blueprint $t) { $t->dropIndex(['type']); $t->dropColumn('type'); });
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn(['status_message', 'fraud_status']));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['buyer_confirmed_at', 'resolution_agreement']));
        Schema::table('offers', function (Blueprint $t) { $t->dropIndex(['commodity']); $t->dropColumn(['withheld_g', 'commodity', 'moderated_at', 'moderation_reason']); });
    }
};
