<?php
namespace App\Models;
class Notice extends Record
{
    protected $table = 'notifications'; protected function casts(): array { return ['read_at' => 'datetime']; }
}
