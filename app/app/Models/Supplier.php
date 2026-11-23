<?php

namespace App\Models;

class Supplier extends TenantModel
{
    protected $table = 'suppliers';
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
