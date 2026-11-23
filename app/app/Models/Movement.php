<?php

namespace App\Models;

class Movement extends TenantModel
{
    protected $table = 'movements';
    protected function casts(): array
    {
        return ['amount' => 'float', 'date' => 'date'];
    }
    public function company() { return $this->belongsTo(Company::class, 'companyId'); }
}
