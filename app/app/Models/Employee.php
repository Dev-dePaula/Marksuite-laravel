<?php

namespace App\Models;

class Employee extends TenantModel
{
    protected $table = 'employees';
    protected function casts(): array
    {
        return [];
    }
    public function company() { return $this->belongsTo(Company::class, 'companyId'); }
}
