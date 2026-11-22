<?php
namespace App\Models;
class PriceStep extends Record
{
    protected function casts(): array { return ['starts_at' => 'datetime']; }
}
