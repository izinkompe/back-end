<?php
use Illuminate\Support\Facades\{Artisan, Schedule};
use App\Models\{Order, FoodNeed};
use App\Services\{Midtrans, OrderFlow, Activity};

Artisan::command('pangantani:maintain', function () {
    Order::where('status', 'awaiting_payment')->where('payment_due_at', '<=', now())->chunkById(100, function ($orders) {
        foreach ($orders as $order) {
            try { app(Midtrans::class)->expire($order); }
            catch (\Throwable $e) { report($e); }
        }
    });
    // Reconciliation also recovers a missed webhook for already initiated payments.
    Order::whereIn('status', ['awaiting_payment', 'issue'])->whereHas('payment', fn ($q) => $q->whereNot('status', 'not_started'))->chunkById(100, function ($orders) {
        foreach ($orders as $order) { try { app(Midtrans::class)->reconcile($order); } catch (\Throwable $e) { report($e); } }
    });
    Order::whereIn('status', ['awaiting_payment', 'processing', 'ready'])->where('pickup_ends_at', '<', now())->chunkById(100, function ($orders) {
        foreach ($orders as $order) { app(OrderFlow::class)->mutate($order, function ($o) { if (in_array($o->status, ['awaiting_payment', 'processing', 'ready'])) { app(OrderFlow::class)->issue($o, 'Jadwal pengambilan telah lewat.'); } }); }
    });
    Order::where('status', 'awaiting_payment')->whereBetween('payment_due_at', [now(), now()->addMinutes(10)])->each(fn ($o) => Activity::notify($o->buyer_id, 'Tenggat pembayaran segera berakhir.', '/orders/'.$o->id, 'payment-due:'.$o->id));
    FoodNeed::where('status', 'active')->where('needed_at', '<=', now())->update(['status' => 'expired']);
    $this->info('Pemeliharaan selesai.');
})->purpose('Rekonsiliasi pembayaran, kedaluwarsa reservasi, dan pengingat');

Schedule::command('pangantani:maintain')->everyMinute()->withoutOverlapping(10);
Schedule::command('sanctum:prune-expired --hours=24')->daily();
