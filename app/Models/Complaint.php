<?php
namespace App\Models;
class Complaint extends Record
{
    protected $with = ['evidence'];
    public function evidence() { return $this->morphMany(Media::class, 'attachable'); }
    public function messages() { return $this->hasMany(ComplaintMessage::class)->orderBy('id'); }
    
}
