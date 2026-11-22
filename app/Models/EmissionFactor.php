<?php
namespace App\Models;
class EmissionFactor extends Record
{
    protected function casts(): array { return ['active' => 'boolean']; }
}
