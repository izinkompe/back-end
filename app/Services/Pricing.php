<?php
namespace App\Services;
use App\Models\Offer;
use Illuminate\Validation\ValidationException;

class Pricing
{
    public static function grams(string|int|float $kg): int
    {
        if (!preg_match('/^\d{1,6}(?:\.\d{1,3})?$/D', (string) $kg) || (float) $kg <= 0) {
            throw ValidationException::withMessages(['quantity_kg' => 'Berat harus positif, maksimal 999999.999 kg dan tiga desimal.']);
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $kg, 2), 2, '');
        return (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
    }
    public static function total(int $grams, int $price): int { return intdiv($grams * $price + 500, 1000); }
    public function price(Offer $offer): int
    {
        $step = $offer->steps->filter(fn ($s) => $s->starts_at->lte(now()))->sortByDesc('starts_at')->first();
        return max($offer->minimum_price, (int) ($step?->price_per_kg ?? $offer->price_per_kg));
    }
}
