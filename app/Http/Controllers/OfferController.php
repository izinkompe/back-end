<?php
namespace App\Http\Controllers;
use App\Models\{Offer, Address, FarmerVerification, PickupSlot, PriceStep, Order, Media};
use App\Services\{Activity, Pricing, Reminders};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OfferController
{
    public function index(Request $r)
    {
        $v = $r->validate(['q' => 'nullable|string|max:100', 'category_id' => 'nullable|integer', 'min_price' => 'nullable|integer|min:0', 'max_price' => 'nullable|integer|min:0', 'min_stock_kg' => 'nullable|numeric|min:0', 'latitude' => 'required_with:longitude,radius_km|numeric|between:-90,90', 'longitude' => 'required_with:latitude,radius_km|numeric|between:-180,180', 'radius_km' => 'nullable|numeric|min:0.1|max:500', 'sort' => 'nullable|in:newest,price_asc,price_desc,distance', 'closing_soon' => 'nullable|boolean', 'pickup_from' => 'nullable|date', 'pickup_to' => 'nullable|date|after:pickup_from', 'page' => 'nullable|integer|min:1']);
        $q = Offer::with(['steps', 'slots', 'photos'])->where('status', 'active')->where('closes_at', '>', now())->whereRaw('stock_g > reserved_g + withheld_g');
        if (!empty($v['q'])) { $q->where(fn ($search) => $search->where('name', 'like', '%'.$v['q'].'%')->orWhere('commodity', 'like', '%'.$v['q'].'%')); }
        if (!empty($v['category_id'])) { $q->where('category_id', $v['category_id']); }
        if (!empty($v['closing_soon'])) { $q->where('closes_at', '<=', now()->addHours(config('pangantani.closing_soon_hours'))); }
        if (isset($v['min_stock_kg'])) { $q->whereRaw('(CAST(stock_g AS SIGNED) - CAST(reserved_g AS SIGNED) - CAST(withheld_g AS SIGNED)) >= ?', [(int) ceil($v['min_stock_kg'] * 1000)]); }
        if (isset($v['pickup_from']) || isset($v['pickup_to'])) { $q->whereHas('slots', function ($s) use ($v) { if (isset($v['pickup_from'])) { $s->where('starts_at', '>=', $v['pickup_from']); } if (isset($v['pickup_to'])) { $s->where('ends_at', '<=', $v['pickup_to']); } }); }
        $greatest = DB::connection()->getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST';
        $least = DB::connection()->getDriverName() === 'sqlite' ? 'MIN' : 'LEAST';
        $q->select('offers.*')->selectRaw($greatest.'(minimum_price, COALESCE((SELECT price_per_kg FROM price_steps WHERE offer_id = offers.id AND starts_at <= ? ORDER BY starts_at DESC LIMIT 1), price_per_kg)) AS catalog_price', [now()]);
        // Derived table allows portable filtering/sorting on the calculated price and distance.
        if (isset($v['latitude'], $v['longitude'])) {
            $q->selectRaw('6371 * 2 * ASIN(SQRT('.$least.'(1, POWER(SIN(RADIANS(latitude - ?) / 2), 2) + COS(RADIANS(?)) * COS(RADIANS(latitude)) * POWER(SIN(RADIANS(longitude - ?) / 2), 2)))) AS distance_km', [$v['latitude'], $v['latitude'], $v['longitude']]);
        }
        $outer = Offer::query()->fromSub($q->toBase(), 'offers')->with(['steps', 'slots', 'photos']);
        if (isset($v['min_price'])) { $outer->where('catalog_price', '>=', (int) $v['min_price']); }
        if (isset($v['max_price'])) { $outer->where('catalog_price', '<=', (int) $v['max_price']); }
        if (isset($v['radius_km'])) { $outer->whereRaw('distance_km <= CAST(? AS DECIMAL(12,6))', [$v['radius_km']]); }
        $sort = $v['sort'] ?? 'newest';
        abort_if($sort === 'distance' && !isset($v['latitude']), 422, 'Lokasi diperlukan untuk urutan jarak.');
        match ($sort) { 'price_asc' => $outer->orderBy('catalog_price'), 'price_desc' => $outer->orderByDesc('catalog_price'), 'distance' => $outer->orderBy('distance_km'), default => $outer->orderByDesc('created_at') };
        $page = $outer->orderBy('id')->paginate(20);
        return $page->toArray() + ['server_time' => now()->toIso8601String(), 'distance_type' => 'straight_line', 'message' => $page->total() ? null : 'Tidak ada penawaran yang cocok. Hapus filter untuk melihat penawaran lain.', 'clear_filters_url' => url('/api/v1/offers')];
    }
    public function show(Request $r, Offer $offer)
    {
        if ($offer->status === 'draft') { $u = auth('sanctum')->user(); abort_unless($u && $u->hasVerifiedEmail() && ($u->role === 'admin' || $u->id === $offer->farmer_id), 404); }
        return ['offer' => $offer->load(['steps', 'slots', 'photos']), 'similar' => Offer::with('steps')->where('category_id', $offer->category_id)->whereKeyNot($offer->id)->where('status', 'active')->where('closes_at', '>', now())->whereRaw('stock_g > reserved_g + withheld_g')->limit(4)->get()];
    }
    public function mine(Request $r) { return Offer::with(['steps', 'slots', 'photos'])->where('farmer_id', $r->user()->id)->latest()->paginate(20)->through(fn ($offer) => $offer->makeVisible('moderation_reason')); }
    public function store(Request $r) { return $this->save($r, new Offer(['farmer_id' => $r->user()->id])); }
    public function update(Request $r, Offer $offer) { abort_unless($offer->farmer_id === $r->user()->id, 403); return $this->save($r, $offer); }
    private function save(Request $r, Offer $offer)
    {
        $v = $r->validate([
            'name' => 'required|string|max:150', 'commodity' => 'sometimes|required|string|max:150', 'category_id' => 'nullable|exists:categories,id', 'address_id' => 'nullable|integer',
            'description' => 'nullable|string|max:5000', 'surplus_reason' => 'nullable|string|max:2000', 'condition' => 'nullable|string|max:2000',
            'harvest_date' => 'nullable|date_format:Y-m-d|before_or_equal:'.now('Asia/Jakarta')->toDateString(), 'stock_kg' => 'sometimes|numeric|min:0|max:999999.999|decimal:0,3',
            'price_per_kg' => 'sometimes|integer|min:1|max:100000000', 'minimum_price' => 'sometimes|integer|min:1|max:100000000',
            'closes_at' => 'nullable|date|after:now', 'slots' => 'sometimes|array|max:30',
            'slots.*.starts_at' => 'required|date|after:now', 'slots.*.ends_at' => 'required|date|after:slots.*.starts_at',
            'price_steps' => 'sometimes|array|max:30', 'price_steps.*.starts_at' => 'required|date|distinct',
            'price_steps.*.price_per_kg' => 'required|integer|min:1|max:100000000',
            'reason' => 'sometimes|required|string|max:2000', 'fit_for_sale' => 'sometimes|boolean', 'affects_orders' => 'sometimes|boolean',
        ]);
        return DB::transaction(function () use ($r, $offer, $v) {
            if ($offer->exists) { $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail(); }
            $before = $offer->getAttributes();
            $addressId = array_key_exists('address_id', $v) ? $v['address_id'] : $offer->getRawOriginal('address_id');
            $address = $addressId ? Address::where('user_id', $r->user()->id)->findOrFail($addressId) : null;
            $conditionChanged = $offer->exists && array_key_exists('condition', $v) && $offer->condition !== $v['condition'];
            $offer->fill(collect($v)->except(['stock_kg', 'slots', 'price_steps', 'reason', 'fit_for_sale', 'affects_orders'])->all());
            if (!$offer->exists) { $offer->save(); $offer->refresh(); }
            $stock = isset($v['stock_kg']) ? ((float) $v['stock_kg'] == 0 ? 0 : Pricing::grams($v['stock_kg'])) : (int) $offer->stock_g;
            if ($address) { $offer->area = $address->area; $offer->latitude = $address->latitude; $offer->longitude = $address->longitude; }
            else { $offer->area = null; $offer->latitude = null; $offer->longitude = null; }
            $affects = (bool) ($v['affects_orders'] ?? $conditionChanged);
            app(\App\Services\Inventory::class)->correct($offer, $stock, $v['reason'] ?? ($stock < $offer->reserved_g ? 'Koreksi stok: stok fisik kurang dari reservasi.' : 'Perubahan data atau kondisi penawaran.'), (bool) ($v['fit_for_sale'] ?? false), $affects);
            abort_if($offer->minimum_price > $offer->price_per_kg, 422, 'Harga minimum melebihi harga awal.');
            if (array_key_exists('slots', $v)) {
                foreach ($offer->slots()->get() as $oldSlot) { $oldBefore = $oldSlot->getAttributes(); $oldSlot->update(['active' => false]); Activity::audit($oldSlot, 'pickup_slot.retired', $oldBefore); }
                foreach ($v['slots'] as $slot) { $newSlot = $offer->slots()->create($slot); Activity::audit($newSlot, 'pickup_slot.created'); }
            }
            if (array_key_exists('price_steps', $v)) {
                foreach ($offer->steps()->get() as $oldStep) { Activity::audit($oldStep, 'price_step.removed', $oldStep->getAttributes()); }
                $offer->steps()->delete();
                foreach ($v['price_steps'] as $step) { $newStep = $offer->steps()->create($step); Activity::audit($newStep, 'price_step.created'); }
            }
            abort_if($offer->steps()->where(fn ($q) => $q->where('price_per_kg', '<', $offer->minimum_price)->orWhere('price_per_kg', '>', $offer->price_per_kg))->exists(), 422, 'Tahap harga melampaui batas harga.');
            if ($offer->status === 'active') { $this->assertComplete($offer); }
            Activity::audit($offer, 'offer.saved', $before, $v['reason'] ?? null);
            return $offer->load(['slots', 'steps', 'photos']);
        });
    }
    private function assertComplete(Offer $offer): void
    {
        abort_unless($offer->name && $offer->category_id && $offer->getRawOriginal('address_id') && $offer->surplus_reason && $offer->condition && $offer->harvest_date && $offer->price_per_kg >= $offer->minimum_price && $offer->minimum_price > 0 && $offer->closes_at?->isFuture() && $offer->photos()->exists() && $offer->slots()->where('starts_at', '>', $offer->closes_at)->whereColumn('ends_at', '>', 'starts_at')->exists(), 422, 'Lengkapi kategori, alamat, kondisi, panen, foto, harga, tenggat dan jadwal setelah tenggat.');
    }
    public function inventory(Request $r, Offer $offer)
    {
        abort_unless($offer->farmer_id === $r->user()->id, 403);
        $v = $r->validate(['stock_kg' => 'required|numeric|min:0|max:999999.999|decimal:0,3', 'reason' => 'required|string|max:2000', 'condition' => 'sometimes|required|string|max:2000', 'fit_for_sale' => 'sometimes|boolean', 'affects_orders' => 'sometimes|boolean']);
        return DB::transaction(function () use ($offer, $v) {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $changed = isset($v['condition']) && $v['condition'] !== $offer->condition;
            if (isset($v['condition'])) { $before = $offer->getAttributes(); $offer->update(['condition' => $v['condition']]); Activity::audit($offer, 'condition.updated', $before, $v['reason']); }
            $stock = (float) $v['stock_kg'] == 0 ? 0 : Pricing::grams($v['stock_kg']);
            app(\App\Services\Inventory::class)->correct($offer, $stock, $v['reason'], (bool) ($v['fit_for_sale'] ?? false), (bool) ($v['affects_orders'] ?? $changed));
            return $offer;
        });
    }
    public function confirmResale(Request $r, Offer $offer)
    {
        abort_unless($offer->farmer_id === $r->user()->id, 403);
        $v = $r->validate(['fit_for_sale' => 'required|accepted', 'reason' => 'required|string|max:2000']);
        return DB::transaction(function () use ($offer, $v) {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            app(\App\Services\Inventory::class)->confirmResale($offer, $v['reason']); return $offer;
        });
    }
    public function pricePreview(Request $r, Offer $offer)
    {
        abort_unless($offer->farmer_id === $r->user()->id, 403);
        return ['initial_price' => $offer->price_per_kg, 'minimum_price' => $offer->minimum_price, 'current_price' => $offer->current_price, 'steps' => $offer->steps, 'server_time' => now()->toIso8601String()];
    }
    public function publish(Request $r, Offer $offer)
    {
        abort_unless($offer->farmer_id === $r->user()->id, 403);
        return DB::transaction(function () use ($offer, $r) {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            abort_if($offer->moderated_at, 403, 'Penawaran dinonaktifkan admin; perlu peninjauan admin sebelum diterbitkan kembali.');
            abort_unless(FarmerVerification::where('user_id', $r->user()->id)->where('status', 'approved')->exists(), 403, 'Petani belum terverifikasi.');
            $this->assertComplete($offer);
            abort_unless($offer->available_g > 0, 422, 'Tidak ada stok layak yang tersedia. Konfirmasikan kelayakan stok yang dilepas.');
            $before = $offer->getAttributes(); $offer->update(['status' => 'active']); Activity::audit($offer, 'offer.published', $before);
            Reminders::forOffer($offer); return $offer;
        });
    }
    public function close(Request $r, Offer $offer)
    {
        abort_unless($offer->farmer_id === $r->user()->id || $r->user()->role === 'admin', 403);
        $v = $r->validate(['reason' => 'required|string|max:2000', 'affects_orders' => 'sometimes|boolean']);
        return DB::transaction(function () use ($offer, $v, $r) {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail(); $before = $offer->getAttributes();
            if ($r->user()->role === 'admin') { $offer->moderated_at = now(); $offer->moderation_reason = $v['reason']; }
            $offer->update(['status' => 'closed']); Activity::audit($offer, 'offer.closed', $before, $v['reason']);
            Activity::notify($offer->farmer_id, 'Penawaran ditutup: '.$v['reason'], '/offers/'.$offer->id);
            if ($v['affects_orders'] ?? false) { foreach (Order::where('offer_id', $offer->id)->whereNotIn('status', ['completed', 'cancelled'])->lockForUpdate()->get() as $order) { app(\App\Services\OrderFlow::class)->issue($order, $v['reason']); } }
            return $offer;
        });
    }
    public function duplicate(Request $r, Offer $offer)
    {
        abort_unless($offer->farmer_id === $r->user()->id, 403);
        return DB::transaction(function () use ($offer) {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $copy = $offer->replicate()->unsetRelations()->fill(['status' => 'draft', 'harvest_date' => null, 'condition' => null, 'stock_g' => 0, 'reserved_g' => 0, 'sold_g' => 0, 'withheld_g' => 0, 'closes_at' => null]);
            $copy->save();
            foreach ($offer->photos()->get() as $photo) { $newPhoto = $photo->replicate(); $newPhoto->attachable_id = $copy->id; $newPhoto->save(); }
            Activity::audit($copy, 'offer.duplicated'); return response()->json($copy, 201);
        });
    }
}
