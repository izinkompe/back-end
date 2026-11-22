<?php
namespace App\Services;
use App\Models\{Offer, Order, Payment, Address, PickupSlot, Distribution, EmissionFactor};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderFlow
{
    public function checkout(int $buyer, array $v, string $key): Order
    {
        $grams = Pricing::grams($v['quantity_kg']);
        $hash = hash('sha256', json_encode([(int) $v['offer_id'], (int) $v['pickup_slot_id'], $grams, (int) $v['expected_price_per_kg']]));
        return DB::transaction(function () use ($buyer, $v, $key, $grams, $hash) {
            // Serialize requests by the same buyer, including attempts reusing a key on another offer.
            \App\Models\User::whereKey($buyer)->lockForUpdate()->firstOrFail();
            if ($existing = Order::where('buyer_id', $buyer)->where('idempotency_key', $key)->first()) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Idempotency-Key sudah dipakai untuk isi pesanan berbeda.'); return $existing->load('payment');
            }
            $offer = Offer::whereKey($v['offer_id'])->lockForUpdate()->firstOrFail();
            abort_unless($offer->effective_status === 'active', 409, 'Penawaran tidak tersedia.');
            abort_if($offer->farmer_id === $buyer, 422, 'Tidak dapat memesan penawaran sendiri.');
            $slot = PickupSlot::where('offer_id', $offer->id)->where('active', true)->findOrFail($v['pickup_slot_id']);
            abort_unless($slot->starts_at->isFuture() && $slot->ends_at->gt($slot->starts_at), 409, 'Jadwal tidak berlaku.');
            $price = $offer->current_price;
            abort_unless($price === (int) $v['expected_price_per_kg'], 409, 'Harga berubah. Muat ulang lalu setujui harga terbaru.');
            abort_if($grams > $offer->available_g, 409, 'Stok tidak mencukupi.');
            $total = Pricing::total($grams, $price); abort_if($total < 1 || $total > 999999999, 422, 'Total harus Rp1 sampai Rp999.999.999.');
            $address = Address::findOrFail($offer->address_id);
            $due = collect([now()->addMinutes(max(1, config('pangantani.payment_ttl'))), $offer->closes_at, $slot->starts_at])->sort()->first();
            $order = Order::create([
                'number' => 'PT-'.Str::ulid(), 'buyer_id' => $buyer, 'farmer_id' => $offer->farmer_id, 'offer_id' => $offer->id,
                'idempotency_key' => $key, 'request_hash' => $hash, 'quantity_g' => $grams, 'price_per_kg' => $price, 'total_idr' => $total,
                'product_snapshot' => ['name' => $offer->name, 'commodity' => $offer->commodity ?? $offer->name, 'category_id' => $offer->category_id, 'condition' => $offer->condition, 'harvest_date' => $offer->harvest_date?->toDateString(), 'area' => $offer->area],
                'pickup_snapshot' => $address->only(['address', 'area', 'latitude', 'longitude', 'instructions']),
                'pickup_starts_at' => $slot->starts_at, 'pickup_ends_at' => $slot->ends_at, 'payment_due_at' => $due,
            ]);
            $before = $offer->getAttributes(); $offer->increment('reserved_g', $grams);
            Payment::create(['order_id' => $order->id, 'gateway_order_id' => $order->number]);
            Activity::audit($offer, 'stock.reserved', $before); Activity::audit($order, 'order.created');
            Activity::notify($buyer, 'Pesanan dibuat. Selesaikan pembayaran.', '/orders/'.$order->id);
            return $order->fresh('payment');
        }, 3);
    }

    // All mutations lock offer before order to keep the lock order consistent with checkout.
    public function mutate(Order $order, callable $callback): Order
    {
        return DB::transaction(function () use ($order, $callback) {
            $offer = Offer::whereKey($order->offer_id)->lockForUpdate()->firstOrFail();
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $before = $order->getAttributes(); $callback($order, $offer);
            $order->refresh();
            Activity::audit($order, 'order.updated', $before);
            if (($before['status'] ?? null) !== $order->status || ($before['payment_status'] ?? null) !== $order->payment_status) {
                foreach ([$order->buyer_id, $order->farmer_id] as $user) { Activity::notify($user, 'Status pesanan: '.$order->status.'; pembayaran: '.$order->payment_status, '/orders/'.$order->id); }
            }
            return $order->load(['payment', 'refund']);
        }, 3);
    }
    public function release(Order $order, Offer $offer): void
    {
        if ($order->stock_state !== 'reserved') { return; }
        $before = $offer->getAttributes();
        abort_if($offer->reserved_g < $order->quantity_g, 409, 'Reservasi stok tidak konsisten.');
        $offer->reserved_g -= $order->quantity_g;
        // BR-06: released stock requires a fresh suitability declaration from the farmer.
        $offer->withheld_g = min($offer->stock_g, (int) $offer->withheld_g + $order->quantity_g);
        $offer->save();
        $order->stock_state = 'released'; $order->save();
        Activity::audit($offer, 'stock.released', $before);
        Activity::notify($order->farmer_id, 'Reservasi dilepas. Periksa kondisi sebelum menawarkan kembali stok.', '/offers/'.$offer->id, 'resale:'.$order->id);
    }
    public function issue(Order $order, string $reason): void
    {
        $before = $order->getAttributes(); $order->update(['status' => 'issue', 'issue_reason' => $reason]);
        Activity::audit($order, 'order.issue', $before, $reason);
        foreach ([$order->buyer_id, $order->farmer_id] as $id) { Activity::notify($id, 'Pesanan memerlukan penanganan: '.$reason, '/orders/'.$order->id); }
    }
    public function complete(Order $order, Offer $offer, int $received, ?string $reason = null): void
    {
        if ($order->status === 'completed') { abort_unless($order->received_g === $received, 409, 'Berat akhir sudah tercatat.'); return; }
        abort_unless(in_array($order->payment_status, ['paid', 'partial_refund'], true) && $order->stock_state === 'reserved', 409, 'Pesanan belum dibayar atau reservasi tidak aktif.');
        abort_if($received > $order->quantity_g || $received < 1, 422, 'Berat akhir harus positif dan tidak melebihi pesanan.');
        abort_unless($order->buyer_confirmed_at, 409, 'Pembeli belum mengonfirmasi penerimaan.');
        abort_if($received > $offer->stock_g || $offer->reserved_g < $order->quantity_g, 409, 'Stok fisik tidak cukup untuk berat yang akan diselesaikan.');
        $before = $offer->getAttributes();
        $offer->reserved_g -= $order->quantity_g;
        // Unreceived quantity is removed from sale until the farmer explicitly corrects stock.
        $offer->stock_g -= min($order->quantity_g, $offer->stock_g);
        $offer->withheld_g = min((int) $offer->withheld_g, $offer->stock_g);
        $offer->sold_g += $received; $offer->save();
        $order->update(['status' => 'completed', 'stock_state' => 'consumed', 'received_g' => $received, 'completed_at' => now(), 'issue_reason' => $reason]);
        $impact = app(Impact::class); $factor = $impact->factor($order->product_snapshot['category_id']); $snapshot = $impact->snapshot($factor);
        Distribution::firstOrCreate(['order_id' => $order->id], ['received_g' => $received, 'emission_factor_id' => $factor?->id, 'estimated_co2e_kg' => $impact->estimate($received, $snapshot), 'factor_snapshot' => $snapshot]);
        Activity::audit($offer, 'stock.consumed', $before); Activity::notify($order->farmer_id, 'Pesanan selesai.', '/orders/'.$order->id, 'completed:'.$order->id);
    }
    public function correctDistribution(Order $order, int $received, int $expected, string $key, string $reason, string $agreement): Order
    {
        $hash = hash('sha256', json_encode([$received, $expected, $reason, $agreement]));
        return $this->mutate($order, function (Order $o, Offer $offer) use ($received, $expected, $key, $hash, $reason, $agreement) {
            $old = \App\Models\DistributionCorrection::where('order_id', $o->id)->where('idempotency_key', $key)->first();
            if ($old) { abort_unless(hash_equals($old->request_hash, $hash), 409, 'Key koreksi telah digunakan untuk payload berbeda.'); return; }
            abort_unless($o->status === 'completed' && $o->stock_state === 'consumed', 409, 'Hanya transaksi selesai dapat dikoreksi.');
            abort_unless($o->received_g === $expected, 409, 'Berat laporan telah berubah. Muat ulang sebelum mengoreksi.');
            abort_if($received > $o->quantity_g || $received < 0, 422, 'Berat koreksi tidak boleh melebihi pesanan.');
            $ledger = Distribution::where('order_id', $o->id)->lockForUpdate()->firstOrFail();
            $delta = $received - $o->received_g;
            abort_if($offer->sold_g + $delta < 0, 409, 'Akumulasi penjualan tidak konsisten.');
            $beforeOffer = $offer->getAttributes(); $beforeLedger = $ledger->getAttributes();
            $offer->sold_g += $delta; $offer->save();
            // Historical factors remain fixed, including null for data never calculated.
            $snapshot = $ledger->factor_snapshot;
            if ($snapshot && !isset($snapshot['method_code'])) { $snapshot['method_code'] = Impact::METHOD; }
            $ledger->update(['received_g' => $received, 'estimated_co2e_kg' => app(Impact::class)->estimate($received, $snapshot)]);
            \App\Models\DistributionCorrection::create(['order_id' => $o->id, 'recorded_by' => auth()->id(), 'idempotency_key' => $key, 'request_hash' => $hash, 'old_received_g' => $o->received_g, 'new_received_g' => $received, 'reason' => $reason, 'agreement' => $agreement]);
            $o->update(['received_g' => $received, 'resolution_agreement' => $agreement]);
            Activity::audit($offer, 'sales.corrected', $beforeOffer, $reason); Activity::audit($ledger, 'distribution.corrected', $beforeLedger, $reason);
            foreach ([$o->buyer_id, $o->farmer_id] as $user) { Activity::notify($user, 'Berat laporan pesanan dikoreksi oleh admin.', '/orders/'.$o->id); }
        });
    }
}
