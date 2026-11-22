<?php
namespace App\Models;
class Distribution extends Record
{
    protected function casts(): array { return ['factor_snapshot' => 'array']; }
}
