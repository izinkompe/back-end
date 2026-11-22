<?php
namespace App\Models;
class DistributionCorrection extends Record
{
    protected $hidden = ['request_hash'];
    protected function casts(): array { return ['old_received_g' => 'integer', 'new_received_g' => 'integer']; }
}
