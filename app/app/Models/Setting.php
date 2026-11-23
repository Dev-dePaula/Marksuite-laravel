<?php

namespace App\Models;

class Setting extends TenantModel
{
    protected $table = 'settings';
    public $timestamps = false;
    protected function casts(): array
    {
        return [];
    }
}
