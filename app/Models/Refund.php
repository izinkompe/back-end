<?php
namespace App\Models;
class Refund extends Record
{
    protected $with = ['evidence'];
    public function evidence() { return $this->morphMany(Media::class, 'attachable'); }
    
}
