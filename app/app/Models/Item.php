<?php

namespace App\Models;

class Item extends TenantModel
{
    protected $table = 'items';
    protected function casts(): array
    {
        return ['price' => 'float', 'ivaRate' => 'float', 'active' => 'boolean'];
    }
}
