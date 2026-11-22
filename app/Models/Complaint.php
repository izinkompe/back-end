<?php
namespace App\Models;
class Complaint extends Record
{
    protected $with = ['evidence'];
    public function evidence() { return $this->morphMany(Media::class, 'attachable'); }
    
}
