<?php
namespace App\Models;
class AuditLog extends Record
{
    protected function casts(): array { return ['before' => 'array', 'after' => 'array']; }
}
