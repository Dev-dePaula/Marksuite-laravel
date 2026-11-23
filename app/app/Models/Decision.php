<?php

namespace App\Models;

class Decision extends TenantModel
{
    protected $table = 'decisions';
    protected function casts(): array
    {
        return ['amount' => 'float', 'postponedUntil' => 'date', 'decidedAt' => 'datetime'];
    }
    public function decidedBy() { return $this->belongsTo(User::class, 'decidedById'); }
}
