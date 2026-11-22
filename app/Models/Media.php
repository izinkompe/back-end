<?php
namespace App\Models;
class Media extends Record
{
    protected $table = 'media';
    protected $hidden = ['path'];
    protected $appends = ['url'];
    public function attachable() { return $this->morphTo(); }
    public function getUrlAttribute(): string { return url('/api/v1/media/'.$this->id); }
}
