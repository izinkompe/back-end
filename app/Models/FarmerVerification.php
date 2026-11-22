<?php
namespace App\Models;
class FarmerVerification extends Record
{
    protected $with = ['evidence', 'applicant'];
    public function evidence() { return $this->morphMany(Media::class, 'attachable'); }
    public function applicant() { return $this->belongsTo(User::class, 'user_id')->select(['id', 'name', 'email', 'phone', 'organization']); }
    protected function casts(): array { return ['reviewed_at' => 'datetime']; }
}
