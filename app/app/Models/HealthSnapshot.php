<?php

namespace App\Models;

class HealthSnapshot extends TenantModel
{
    protected $table = 'health_snapshots';
    protected function casts(): array
    {
        return ['month' => 'date'];
    }
}
