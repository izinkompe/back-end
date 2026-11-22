<?php
namespace App\Http\Controllers;
use App\Models\{Favorite, FoodNeed, NeedResponse, Offer, PickupSlot};
use App\Services\Pricing;
use Illuminate\Http\Request;

class CommunityController
{
    public function favorites(Request $r) { return Favorite::where('user_id', $r->user()->id)->get(); }
    public function favorite(Request $r)
    {
        $v = $r->validate(['kind' => 'required|in:farmer,commodity', 'value' => 'required|string|max:150']);
        if ($v['kind'] === 'farmer') { abort_unless(\App\Models\User::where('role', 'farmer')->whereKey($v['value'])->exists(), 422, 'Petani tidak ditemukan.'); }
        $v['value'] = mb_strtolower(trim($v['value']));
        return Favorite::firstOrCreate($v + ['user_id' => $r->user()->id]);
    }
    public function unfavorite(Request $r, Favorite $favorite) { abort_unless($favorite->user_id === $r->user()->id, 403); $favorite->delete(); return response()->noContent(); }
    public function needs(Request $r)
    {
        $v = $r->validate(['mine' => 'nullable|boolean']);
        $q = FoodNeed::query()->latest();
        if ($v['mine'] ?? false) { $q->where('buyer_id', $r->user()->id); }
        else { $q->where('status', 'active')->where('needed_at', '>', now()); }
        return $q->paginate(20);
    }
    public function need(Request $r)
    {
        $v = $r->validate(['commodity' => 'required|string|max:150', 'quantity_kg' => 'required|numeric', 'area' => 'required|string|max:255', 'needed_at' => 'required|date|after:now']);
        return FoodNeed::create(collect($v)->except('quantity_kg')->all() + ['quantity_g' => Pricing::grams($v['quantity_kg']), 'buyer_id' => $r->user()->id]);
    }
    public function closeNeed(Request $r, FoodNeed $need)
    {
        abort_unless($need->buyer_id === $r->user()->id, 403); $v = $r->validate(['status' => 'required|in:fulfilled,closed']); $need->update($v); return $need;
    }
    public function responses(Request $r, FoodNeed $need)
    {
        $q = NeedResponse::where('food_need_id', $need->id);
        if ($need->buyer_id !== $r->user()->id && $r->user()->role !== 'admin') { $q->where('farmer_id', $r->user()->id); }
        return $q->latest()->paginate(20);
    }
    public function respond(Request $r, FoodNeed $need)
    {
        abort_unless($need->status === 'active' && $need->needed_at->isFuture(), 409, 'Kebutuhan sudah ditutup.');
        $v = $r->validate(['offer_id' => 'required|integer', 'pickup_slot_id' => 'required|integer', 'quantity_kg' => 'required|numeric', 'message' => 'nullable|string|max:2000']);
        $offer = Offer::where('farmer_id', $r->user()->id)->findOrFail($v['offer_id']);
        abort_unless($offer->effective_status === 'active', 409, 'Penawaran tidak aktif.');
        PickupSlot::where('offer_id', $offer->id)->where('active', true)->where('starts_at', '>', now())->findOrFail($v['pickup_slot_id']);
        $grams = Pricing::grams($v['quantity_kg']); abort_if($grams > $offer->stock_g - $offer->reserved_g, 409, 'Stok tidak cukup.');
        return NeedResponse::create(collect($v)->except('quantity_kg')->all() + ['food_need_id' => $need->id, 'farmer_id' => $r->user()->id, 'quantity_g' => $grams, 'price_per_kg' => $offer->current_price]);
    }
    public function select(Request $r, FoodNeed $need, NeedResponse $response)
    {
        abort_unless($need->buyer_id === $r->user()->id && $response->food_need_id === $need->id, 403);
        abort_unless($need->status === 'active' && $need->needed_at->isFuture(), 409);
        $offer = Offer::findOrFail($response->offer_id); abort_unless($offer->effective_status === 'active', 409);
        // Selection only prepares checkout. The normal POST /orders performs all current checks.
        return ['checkout' => ['offer_id' => $offer->id, 'pickup_slot_id' => $response->pickup_slot_id, 'quantity_kg' => number_format($response->quantity_g / 1000, 3, '.', ''), 'expected_price_per_kg' => $offer->current_price], 'quoted_price_per_kg' => $response->price_per_kg];
    }
}
