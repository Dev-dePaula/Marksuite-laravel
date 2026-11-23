<?php

namespace App\Models;

class Automation extends TenantModel
{
    protected $table = 'automations';
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
