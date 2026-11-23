<?php

namespace App\Models;

class OppContact extends TenantModel
{
    protected $table = 'opp_contacts';
    protected function casts(): array
    {
        return [];
    }
}
