<?php
namespace App\Models;
class ComplaintMessage extends Record
{
    protected $with = ['evidence'];
    public function evidence() { return $this->morphMany(Media::class, 'attachable'); }
}
