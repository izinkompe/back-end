<?php
namespace App\Services;

use App\Models\{Offer, Order};

class Inventory
{
    /** Called only while holding the offer row lock. Physical stock may be below reservations. */
    public function correct(Offer $offer, int $grams, string $reason, bool $fitForSale = false, bool $affectsOrders = false): void
    {
        $before = $offer->getAttributes();
        $offer->stock_g = $grams;
        $offer->withheld_g = $fitForSale ? 0 : min((int) $offer->withheld_g, $grams);
        if ($grams < $offer->reserved_g || $affectsOrders) {
            $offer->status = 'closed';
            $offer->save();
            foreach (Order::where('offer_id', $offer->id)->where('stock_state', 'reserved')->whereNotIn('status', ['completed', 'cancelled'])->lockForUpdate()->get() as $order) {
                app(OrderFlow::class)->issue($order, $reason);
            }
        }
        $offer->save(); Activity::audit($offer, 'stock.corrected', $before, $reason);
    }
    public function confirmResale(Offer $offer, string $reason): void
    {
        $before = $offer->getAttributes(); $offer->update(['withheld_g' => 0]);
        Activity::audit($offer, 'stock.resale_confirmed', $before, $reason);
    }
}
