<?php
namespace App\Models;
class Offer extends Record
{
    protected $hidden = ['address_id', 'moderation_reason'];
    protected $appends = ['available_kg', 'available_g', 'shortage_g', 'current_price', 'effective_status', 'seconds_until_close', 'share_url'];
    protected function casts(): array { return ['closes_at' => 'datetime', 'moderated_at' => 'datetime', 'harvest_date' => 'date', 'stock_g' => 'integer', 'reserved_g' => 'integer', 'sold_g' => 'integer', 'withheld_g' => 'integer', 'price_per_kg' => 'integer', 'minimum_price' => 'integer']; }
    public function steps() { return $this->hasMany(PriceStep::class)->orderBy('starts_at'); }
    public function slots() { return $this->hasMany(PickupSlot::class)->where('active', true); }
    public function photos() { return $this->morphMany(Media::class, 'attachable')->where('kind', 'offer_photo'); }
    public function getAvailableGAttribute(): int { return max(0, $this->stock_g - $this->reserved_g - (int) $this->withheld_g); }
    public function getShortageGAttribute(): int { return max(0, $this->reserved_g - $this->stock_g); }
    public function getAvailableKgAttribute(): string { return number_format($this->available_g / 1000, 3, '.', ''); }
    public function getSecondsUntilCloseAttribute(): ?int { return $this->closes_at ? max(0, (int) now()->diffInSeconds($this->closes_at, false)) : null; }
    public function getShareUrlAttribute(): string { return config('pangantani.frontend_url').'/offers/'.$this->id; }
    public function getCurrentPriceAttribute(): int { return app(\App\Services\Pricing::class)->price($this); }
    public function getEffectiveStatusAttribute(): string { return $this->status !== 'active' ? $this->status : (($this->closes_at?->lte(now())) ? 'expired' : (($this->available_g <= 0) ? 'sold_out' : 'active')); }
    public function toArray(): array
    {
        $data = parent::toArray();
        foreach (['latitude', 'longitude'] as $field) { if (isset($data[$field])) { $data[$field] = round((float) $data[$field], 2); } }
        return $data;
    }
}
