<?php
namespace App\Models;
class Payment extends Record
{
    protected $hidden = ['snap_token', 'redirect_url']; protected function casts(): array { return ['initiated_at' => 'datetime', 'checked_at' => 'datetime']; }
}
