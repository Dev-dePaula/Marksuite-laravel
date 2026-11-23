<?php

namespace App\Models;

class Account extends TenantModel
{
    protected $table = 'accounts';
    protected function casts(): array
    {
        return ['synthetic' => 'boolean', 'isSystem' => 'boolean', 'active' => 'boolean'];
    }
}
