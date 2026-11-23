<?php

namespace App\Models;

class DocCounter extends TenantModel
{
    protected $table = 'doc_counters';
    public $timestamps = false;
    protected function casts(): array
    {
        return [];
    }
}
