<?php
namespace App\Models;
class FoodNeed extends Record
{
    protected function casts(): array { return ['needed_at' => 'datetime']; }
}
