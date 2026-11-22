<?php
namespace App\Services;
use App\Models\{Offer, Favorite, User};

class Reminders
{
    public static function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;
        return 6371 * 2 * asin(sqrt(min(1, $a)));
    }
    public static function forOffer(Offer $offer): void
    {
        $ids = Favorite::where(fn ($q) => $q->where(fn ($f) => $f->where('kind', 'farmer')->where('value', (string) $offer->farmer_id))->orWhere(fn ($f) => $f->where('kind', 'commodity')->where('value', mb_strtolower($offer->name))))->pluck('user_id');
        foreach (User::whereIn('id', $ids)->cursor() as $user) {
            $p = $user->preferences ?? [];
            if (!($p['reminders'] ?? false)) { continue; }
            if (isset($p['radius_km'])) {
                if (!isset($p['latitude'], $p['longitude'], $offer->latitude, $offer->longitude)) { continue; }
                if (self::distance($p['latitude'], $p['longitude'], $offer->latitude, $offer->longitude) > $p['radius_km']) { continue; }
            }
            Activity::notify($user->id, 'Penawaran baru: '.$offer->name, '/offers/'.$offer->id, 'offer:'.$offer->id);
        }
    }
}
