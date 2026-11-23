<?php

namespace App\Models;

class MemoryEntry extends TenantModel
{
    protected $table = 'memory_entries';
    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
