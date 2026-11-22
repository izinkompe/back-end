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
        $q = Offer::with(['steps', 'slots', 'photos'])->where('status', 'active')->where('closes_at', '>', now())->whereColumn('stock_g', '>', 'reserved_g');
        if (!empty($v['q'])) { $q->where('name', 'like', '%'.$v['q'].'%'); }
        if (!empty($v['category_id'])) { $q->where('category_id', $v['category_id']); }
        if (!empty($v['closing_soon'])) { $q->where('closes_at', '<=', now()->addHours(config('pangantani.closing_soon_hours'))); }
        if (isset($v['min_stock_kg'])) { $q->whereRaw('(stock_g - reserved_g) >= ?', [(int) ceil($v['min_stock_kg'] * 1000)]); }
        if (isset($v['pickup_from']) || isset($v['pickup_to'])) { $q->whereHas('slots', function ($s) use ($v) { if (isset($v['pickup_from'])) { $s->where('starts_at', '>=', $v['pickup_from']); } if (isset($v['pickup_to'])) { $s->where('ends_at', '<=', $v['pickup_to']); } }); }
        $q->select('offers.*')->selectRaw('GREATEST(minimum_price, COALESCE((SELECT price_per_kg FROM price_steps WHERE offer_id = offers.id AND starts_at <= ? ORDER BY starts_at DESC LIMIT 1), price_per_kg)) AS catalog_price', [now()]);
        // Derived table allows portable filtering/sorting on the calculated price and distance.
        if (isset($v['latitude'], $v['longitude'])) {
            $q->selectRaw('6371 * 2 * ASIN(SQRT(LEAST(1, POWER(SIN(RADIANS(latitude - ?) / 2), 2) + COS(RADIANS(?)) * COS(RADIANS(latitude)) * POWER(SIN(RADIANS(longitude - ?) / 2), 2)))) AS distance_km', [$v['latitude'], $v['latitude'], $v['longitude']]);
        }
        $outer = Offer::query()->fromSub($q->toBase(), 'offers')->with(['steps', 'slots', 'photos']);
        if (isset($v['min_price'])) { $outer->where('catalog_price', '>=', $v['min_price']); }
        if (isset($v['max_price'])) { $outer->where('catalog_price', '<=', $v['max_price']); }
        if (isset($v['radius_km'])) { $outer->where('distance_km', '<=', $v['radius_km']); }
        $sort = $v['sort'] ?? 'newest';
        abort_if($sort === 'distance' && !isset($v['latitude']), 422, 'Lokasi diperlukan untuk urutan jarak.');
        match ($sort) { 'price_asc' => $outer->orderBy('catalog_price'), 'price_desc' => $outer->orderByDesc('catalog_price'), 'distance' => $outer->orderBy('distance_km'), default => $outer->orderByDesc('created_at') };
        return $outer->orderBy('id')->paginate(20);
    }
    public function show(Request $r, Offer $offer)
    {
        if ($offer->status === 'draft') { $u = auth('sanctum')->user(); abort_unless($u && ($u->role === 'admin' || $u->id === $offer->farmer_id), 404); }
        return ['offer' => $offer->load(['steps', 'slots', 'photos']), 'similar' => Offer::with('steps')->where('category_id', $offer->category_id)->whereKeyNot($offer->id)->where('status', 'active')->where('closes_at', '>', now())->whereColumn('stock_g', '>', 'reserved_g')->limit(4)->get()];
    }
    public function mine(Request $r) { return Offer::with(['steps', 'slots', 'photos'])->where('farmer_id', $r->user()->id)->latest()->paginate(20); }
    public function store(Request $r) { return $this->save($r, new Offer(['farmer_id' => $r->user()->id])); }
    public function update(Request $r, Offer $offer) { abort_unless($offer->farmer_id === $r->user()->id, 403); return $this->save($r, $offer); }
    private function save(Request $r, Offer $offer)
    {
        $v = $r->validate(['name' => 'required|string|max:150', 'category_id' => 'nullable|exists:categories,id', 'address_id' => 'nullable|integer', 'description' => 'nullable|string|max:5000', 'surplus_reason' => 'nullable|string|max:2000', 'condition' => 'nullable|string|max:2000', 'harvest_date' => 'nullable|date|before_or_equal:today', 'stock_kg' => 'required|numeric|min:0|max:999999.999|decimal:0,3', 'price_per_kg' => 'required|integer|min:1|max:100000000', 'minimum_price' => 'required|integer|min:1|lte:price_per_kg', 'closes_at' => 'nullable|date|after:now', 'slots' => 'required|array|max:30', 'slots.*.starts_at' => 'required|date|after:now', 'slots.*.ends_at' => 'required|date|after:slots.*.starts_at', 'price_steps' => 'sometimes|array|max:30', 'price_steps.*.starts_at' => 'required|date|distinct', 'price_steps.*.price_per_kg' => 'required|integer|min:1|gte:minimum_price|lte:price_per_kg']);
        return DB::transaction(function () use ($r, $offer, $v) {
            if ($offer->exists) { $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail(); }
            $before = $offer->getAttributes();
            $address = isset($v['address_id']) ? Address::where('user_id', $r->user()->id)->findOrFail($v['address_id']) : null;
            $stock = (float) $v['stock_kg'] == 0 ? 0 : Pricing::grams($v['stock_kg']);
            // stock_g is unsold physical stock, including reservations; corrections cannot consume reserved stock.
            abort_if($stock < (int) $offer->reserved_g, 409, 'Stok di bawah reservasi. Ajukan masalah pada pesanan aktif terlebih dahulu.');
            $offer->fill(collect($v)->except(['stock_kg', 'slots', 'price_steps'])->all());
            $offer->stock_g = $stock;
            if ($address) { $offer->area = $address->area; $offer->latitude = $address->latitude; $offer->longitude = $address->longitude; }
            if ($offer->status === 'active') { abort_unless($address && !empty($v['surplus_reason']) && !empty($v['condition']) && !empty($v['harvest_date']) && !empty($v['category_id']) && !empty($v['closes_at']) && count($v['slots']), 422, 'Penawaran aktif harus tetap lengkap.'); }
            $offer->save();
            $offer->slots()->update(['active' => false]);
            foreach ($v['slots'] as $slot) { $offer->slots()->create($slot); }
            if (array_key_exists('price_steps', $v)) {
                foreach ($offer->steps()->get() as $oldStep) { Activity::audit($oldStep, 'price_step.removed', $oldStep->getAttributes()); }
                $offer->steps()->delete();
                foreach ($v['price_steps'] as $step) { $newStep = $offer->steps()->create($step); Activity::audit($newStep, 'price_step.created'); }
            }
            // Existing steps must remain within a changed price floor/ceiling.
            abort_if($offer->steps()->where(fn ($q) => $q->where('price_per_kg', '<', $offer->minimum_price)->orWhere('price_per_kg', '>', $offer->price_per_kg))->exists(), 422, 'Tahap harga melampaui batas harga baru.');
            Activity::audit($offer, 'offer.saved', $before);
            return $offer->load(['slots', 'steps', 'photos']);
        });
    }
    public function publish(Request $r, Offer $offer)
    {
        abort_unless($offer->farmer_id === $r->user()->id, 403);
        return DB::transaction(function () use ($offer, $r) {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            abort_unless(FarmerVerification::where('user_id', $r->user()->id)->where('status', 'approved')->exists(), 403, 'Petani belum terverifikasi.');
            abort_unless($offer->name && $offer->category_id && $offer->address_id && $offer->surplus_reason && $offer->condition && $offer->harvest_date && $offer->price_per_kg >= $offer->minimum_price && $offer->minimum_price > 0 && $offer->stock_g > $offer->reserved_g && $offer->closes_at?->isFuture() && $offer->photos()->exists() && $offer->slots()->where('starts_at', '>', $offer->closes_at)->exists(), 422, 'Lengkapi kategori, alamat, kondisi, panen, foto, stok, harga, tenggat dan jadwal setelah tenggat.');
            $before = $offer->getAttributes(); $offer->update(['status' => 'active']); Activity::audit($offer, 'offer.published', $before);
            Reminders::forOffer($offer); return $offer;
        });
    }
    public function close(Request $r, Offer $offer)
    {
        abort_unless($offer->farmer_id === $r->user()->id || $r->user()->role === 'admin', 403);
        $v = $r->validate(['reason' => 'required|string|max:2000', 'affects_orders' => 'sometimes|boolean']);
        return DB::transaction(function () use ($offer, $v) {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail(); $before = $offer->getAttributes();
            $offer->update(['status' => 'closed']); Activity::audit($offer, 'offer.closed', $before, $v['reason']);
            if ($v['affects_orders'] ?? false) { foreach (Order::where('offer_id', $offer->id)->whereNotIn('status', ['completed', 'cancelled'])->lockForUpdate()->get() as $order) { app(\App\Services\OrderFlow::class)->issue($order, $v['reason']); } }
            return $offer;
        });
    }
    public function duplicate(Request $r, Offer $offer)
    {
        abort_unless($offer->farmer_id === $r->user()->id, 403);
        $copy = $offer->replicate()->fill(['status' => 'draft', 'harvest_date' => null, 'condition' => null, 'stock_g' => 0, 'reserved_g' => 0, 'sold_g' => 0, 'closes_at' => null]);
        $copy->save(); return response()->json($copy, 201);
    }
}
