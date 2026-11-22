<?php
namespace App\Models;
class Offer extends Record
{
    protected $hidden = ['address_id'];
    protected $appends = ['available_kg', 'current_price', 'effective_status'];
    protected function casts(): array { return ['closes_at' => 'datetime', 'harvest_date' => 'date', 'stock_g' => 'integer', 'reserved_g' => 'integer', 'sold_g' => 'integer', 'price_per_kg' => 'integer', 'minimum_price' => 'integer']; }
    public function steps() { return $this->hasMany(PriceStep::class)->orderBy('starts_at'); }
    public function slots() { return $this->hasMany(PickupSlot::class)->where('active', true); }
    public function photos() { return $this->morphMany(Media::class, 'attachable')->where('kind', 'offer_photo'); }
    public function getAvailableKgAttribute(): string { return number_format(max(0, $this->stock_g - $this->reserved_g) / 1000, 3, '.', ''); }
    public function getCurrentPriceAttribute(): int { return app(\App\Services\Pricing::class)->price($this); }
    public function getEffectiveStatusAttribute(): string { return $this->status !== 'active' ? $this->status : (($this->closes_at?->isPast()) ? 'expired' : (($this->stock_g <= $this->reserved_g) ? 'sold_out' : 'active')); }
    public function toArray(): array
    {
        $data = parent::toArray();
        foreach (['latitude', 'longitude'] as $field) { if (isset($data[$field])) { $data[$field] = round((float) $data[$field], 2); } }
        return $data;
    }
}
