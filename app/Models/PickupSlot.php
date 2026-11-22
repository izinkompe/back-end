<?php
namespace App\Models;
class PickupSlot extends Record
{
    protected function casts(): array { return ['starts_at' => 'datetime', 'ends_at' => 'datetime']; }
}
