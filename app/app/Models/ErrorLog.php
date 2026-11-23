<?php

namespace App\Models;

class ErrorLog extends TenantModel
{
    protected $table = 'error_logs';
    protected function casts(): array
    {
        return [];
    }
}
