<?php

namespace App\Models;

class ActivityLog extends TenantModel
{
    protected $table = 'activity_logs';
    protected function casts(): array
    {
        return [];
    }
}
