<?php

namespace App\Models;

class Company extends TenantModel
{
    protected $table = 'companies';
    protected function casts(): array
    {
        return [];
    }
    public function contacts() { return $this->hasMany(Contact::class, 'companyId'); }
}
