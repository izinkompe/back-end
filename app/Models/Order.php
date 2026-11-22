<?php
namespace App\Models;
class Order extends Record
{
    protected $hidden = ['request_hash'];
    protected function casts(): array { return ['product_snapshot' => 'array', 'pickup_snapshot' => 'array', 'proposed_pickup' => 'array', 'payment_due_at' => 'datetime', 'pickup_starts_at' => 'datetime', 'pickup_ends_at' => 'datetime', 'completed_at' => 'datetime', 'buyer_confirmed_at' => 'datetime', 'quantity_g' => 'integer', 'received_g' => 'integer', 'price_per_kg' => 'integer', 'total_idr' => 'integer']; }
    public function payment() { return $this->hasOne(Payment::class); }
    public function complaints() { return $this->hasMany(Complaint::class); }
    public function refund() { return $this->hasOne(Refund::class); }
    public function corrections() { return $this->hasMany(DistributionCorrection::class); }
}
