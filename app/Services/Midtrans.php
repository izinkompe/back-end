<?php
namespace App\Services;
use App\Models\{Order, Payment, User};
use Illuminate\Support\Facades\Http;

class Midtrans
{
    private function client()
    {
        $key = config('pangantani.midtrans.server_key'); abort_unless($key, 503, 'Midtrans belum dikonfigurasi.');
        return Http::withBasicAuth($key, '')->acceptJson()->asJson()->connectTimeout(5)->timeout(20);
    }
    private function base(): string { return config('pangantani.midtrans.production') ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com'; }
    public function snap(Order $order): array
    {
        $initiate = false;
        $result = app(OrderFlow::class)->mutate($order, function (Order $o) use (&$initiate) {
            abort_unless($o->status === 'awaiting_payment' && $o->payment_due_at->isFuture(), 409, 'Pesanan tidak dapat dibayar.');
            $p = Payment::where('order_id', $o->id)->lockForUpdate()->firstOrFail();
            if ($p->snap_token) { return; }
            abort_unless($p->status === 'not_started', 409, 'Pembayaran pernah diinisiasi. Sinkronkan status atau hubungi admin.');
            abort_if(now()->diffInSeconds($o->payment_due_at) < 60, 409, 'Sisa tenggat kurang dari satu menit.');
            abort_unless(config('pangantani.midtrans.server_key'), 503, 'Midtrans belum dikonfigurasi.');
            // Commit intent before network IO: a process crash cannot make an active gateway payment look unstarted.
            $p->update(['status' => 'initiating', 'initiated_at' => now()]);
            Activity::audit($p, 'payment.initiated'); $initiate = true;
        });
        if ($initiate) {
            $buyer = User::findOrFail($result->buyer_id);
            $base = config('pangantani.midtrans.production') ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com';
            $token = null; $redirect = null;
            try {
                $response = $this->client()->post($base.'/snap/v1/transactions', [
                    'transaction_details' => ['order_id' => $result->number, 'gross_amount' => $result->total_idr],
                    'item_details' => [['id' => (string) $result->offer_id, 'price' => $result->total_idr, 'quantity' => 1, 'name' => mb_substr($result->product_snapshot['name'], 0, 50)]],
                    'customer_details' => ['first_name' => $buyer->name, 'email' => $buyer->email],
                    'expiry' => ['start_time' => now()->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s O'), 'unit' => 'minutes', 'duration' => max(1, (int) floor(now()->diffInSeconds($result->payment_due_at) / 60))],
                    'callbacks' => ['finish' => config('pangantani.frontend_url').'/orders/'.$result->id],
                ]);
                if ($response->successful()) { $token = $response->json('token'); $redirect = $response->json('redirect_url'); }
            } catch (\Illuminate\Http\Client\ConnectionException $e) { /* Ambiguous outcome: retain reservation. */ }
            app(OrderFlow::class)->mutate($result, function (Order $o) use ($token, $redirect) {
                $p = Payment::where('order_id', $o->id)->lockForUpdate()->firstOrFail();
                $changes = ['snap_token' => $token, 'redirect_url' => $redirect];
                // Do not overwrite a webhook that arrived while creating the Snap token.
                if ($p->status === 'initiating') { $changes['status'] = $token && $redirect ? 'pending' : 'uncertain'; }
                $p->update($changes);
            });
        }
        $p = $result->payment()->firstOrFail();
        abort_unless($p->snap_token && $p->redirect_url, 502, 'Hasil inisiasi belum pasti. Sinkronkan status; stok tetap ditahan.');
        return ['snap_token' => $p->snap_token, 'redirect_url' => $p->redirect_url, 'client_key' => config('pangantani.midtrans.client_key'), 'production' => config('pangantani.midtrans.production')];
    }
    public function verifySignature(array $v): void
    {
        $key = config('pangantani.midtrans.server_key'); abort_unless($key, 503);
        $expected = hash('sha512', $v['order_id'].$v['status_code'].$v['gross_amount'].$key);
        abort_unless(hash_equals($expected, $v['signature_key']), 403, 'Signature Midtrans tidak valid.');
    }
    public function reconcile(Order $order): Order
    {
        return app(OrderFlow::class)->mutate($order, function (Order $o, $offer) {
            $p = Payment::where('order_id', $o->id)->lockForUpdate()->firstOrFail();
            try { $response = $this->client()->get($this->base().'/v2/'.rawurlencode($o->number).'/status'); }
            catch (\Illuminate\Http\Client\ConnectionException $e) { abort(502, 'Koneksi Midtrans belum tersedia. Status pembayaran dan stok tetap dipertahankan.'); }
            // 404 is not proof of expiry: a Snap token may exist before a payment method is selected.
            if ($response->status() === 404 || (string) $response->json('status_code') === '404') { $p->update(['checked_at' => now()]); return; }
            abort_unless($response->successful(), 502, 'Tidak dapat memeriksa Midtrans. Coba kembali.');
            $v = $response->json();
            abort_unless(($v['order_id'] ?? '') === $o->number && is_numeric($v['gross_amount'] ?? null) && (float) $v['gross_amount'] === (float) $o->total_idr, 409, 'Nominal atau identitas transaksi berbeda.');
            $status = $v['transaction_status'] ?? ''; $before = $p->getAttributes();
            $paid = $status === 'settlement' || ($status === 'capture' && ($v['fraud_status'] ?? '') === 'accept');
            abort_unless(in_array($status, ['pending', 'settlement', 'capture', 'deny', 'cancel', 'expire', 'refund', 'partial_refund', 'chargeback', 'partial_chargeback', 'authorize'], true), 502, 'Status Midtrans tidak dikenali.');
            $p->update(['status' => $status, 'transaction_id' => $v['transaction_id'] ?? null, 'status_message' => mb_substr((string) ($v['status_message'] ?? ''), 0, 2000), 'fraud_status' => isset($v['fraud_status']) ? mb_substr($v['fraud_status'], 0, 30) : null, 'checked_at' => now()]);
            $flow = app(OrderFlow::class);
            if ($paid && $o->payment_status !== 'paid') {
                $o->payment_status = 'paid';
                if ($o->stock_state !== 'reserved' || $o->status === 'cancelled') { $o->save(); $flow->issue($o, 'Pembayaran diterima setelah reservasi dilepas; tinjau refund.'); }
                elseif ($o->status === 'awaiting_payment') { $o->status = 'processing'; $o->save(); }
                else { $o->save(); }
                Activity::notify($o->farmer_id, 'Pembayaran diterima. Siapkan pesanan.', '/orders/'.$o->id, 'paid:'.$o->id);
                Activity::notify($o->buyer_id, 'Pembayaran terkonfirmasi.', '/orders/'.$o->id, 'paid:'.$o->id);
            } elseif (in_array($status, ['deny', 'cancel', 'expire'], true) && $o->payment_status !== 'paid' && $o->status !== 'completed') {
                $flow->release($o, $offer); $o->update(['status' => 'cancelled', 'payment_status' => 'failed']);
            } elseif (in_array($status, ['refund', 'partial_refund', 'chargeback', 'partial_chargeback'], true)) {
                $o->payment_status = $status; $o->save();
                if ($o->status !== 'completed') { $flow->issue($o, 'Midtrans melaporkan '.$status.'. Admin perlu meninjau stok dan penyelesaian.'); }
            }
            Activity::audit($p, 'payment.reconciled', $before);
        });
    }
    public function expire(Order $order): Order
    {
        return app(OrderFlow::class)->mutate($order, function (Order $o, $offer) {
            if ($o->status !== 'awaiting_payment' || $o->payment_due_at->isFuture()) { return; }
            $p = Payment::where('order_id', $o->id)->lockForUpdate()->firstOrFail();
            if ($p->status === 'not_started') { app(OrderFlow::class)->release($o, $offer); $o->update(['status' => 'cancelled', 'payment_status' => 'expired']); return; }
            // Expire the gateway transaction before releasing; failure/404 retains stock for investigation.
            $this->client()->post($this->base().'/v2/'.rawurlencode($o->number).'/expire');
            $this->reconcile($o);
        });
    }
    public function cancel(Order $order): Order
    {
        return app(OrderFlow::class)->mutate($order, function (Order $o) {
            abort_unless($o->payment_status !== 'paid' && $o->stock_state === 'reserved', 409, 'Pembayaran sudah diterima atau reservasi tidak aktif.');
            $this->client()->post($this->base().'/v2/'.rawurlencode($o->number).'/cancel');
            $this->reconcile($o);
        });
    }
}
