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
            abort_if($grams > $offer->stock_g - $offer->reserved_g, 409, 'Stok tidak mencukupi.');
            $total = Pricing::total($grams, $price); abort_if($total < 1 || $total > 999999999, 422, 'Total harus Rp1 sampai Rp999.999.999.');
            $address = Address::findOrFail($offer->address_id);
            $due = collect([now()->addMinutes(max(1, config('pangantani.payment_ttl'))), $offer->closes_at, $slot->starts_at])->sort()->first();
            $order = Order::create([
                'number' => 'PT-'.Str::ulid(), 'buyer_id' => $buyer, 'farmer_id' => $offer->farmer_id, 'offer_id' => $offer->id,
                'idempotency_key' => $key, 'request_hash' => $hash, 'quantity_g' => $grams, 'price_per_kg' => $price, 'total_idr' => $total,
                'product_snapshot' => ['name' => $offer->name, 'category_id' => $offer->category_id, 'condition' => $offer->condition, 'harvest_date' => $offer->harvest_date?->toDateString(), 'area' => $offer->area],
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
            Activity::audit($order, 'order.updated', $before); return $order->fresh(['payment', 'refund']);
        }, 3);
    }
    public function release(Order $order, Offer $offer): void
    {
        if ($order->stock_state !== 'reserved') { return; }
        $before = $offer->getAttributes();
        abort_if($offer->reserved_g < $order->quantity_g, 409, 'Reservasi stok tidak konsisten.');
        $offer->decrement('reserved_g', $order->quantity_g);
        $order->stock_state = 'released'; $order->save();
        Activity::audit($offer, 'stock.released', $before);
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
        abort_unless($order->payment_status === 'paid' && $order->stock_state === 'reserved', 409, 'Pesanan belum dibayar atau reservasi tidak aktif.');
        abort_if($received > $order->quantity_g || $received < 1, 422, 'Berat akhir harus positif dan tidak melebihi pesanan.');
        $before = $offer->getAttributes();
        $offer->reserved_g -= $order->quantity_g;
        // Unreceived quantity is removed from sale until the farmer explicitly corrects stock.
        $offer->stock_g -= $order->quantity_g; $offer->sold_g += $received; $offer->save();
        $order->update(['status' => 'completed', 'stock_state' => 'consumed', 'received_g' => $received, 'completed_at' => now(), 'issue_reason' => $reason]);
        $factor = EmissionFactor::where('category_id', $order->product_snapshot['category_id'])->where('active', true)->latest('id')->first();
        Distribution::firstOrCreate(['order_id' => $order->id], ['received_g' => $received, 'emission_factor_id' => $factor?->id, 'estimated_co2e_kg' => $factor ? round($received / 1000 * (float) $factor->kg_co2e_per_kg, 6) : null, 'factor_snapshot' => $factor?->only(['kg_co2e_per_kg', 'unit', 'source', 'version', 'method', 'assumptions'])]);
        Activity::audit($offer, 'stock.consumed', $before); Activity::notify($order->farmer_id, 'Pesanan selesai.', '/orders/'.$order->id, 'completed:'.$order->id);
    }
}
