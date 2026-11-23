<?php

namespace App\Models;

class Contact extends TenantModel
{
    protected $table = 'contacts';
    protected function casts(): array
    {
        return ['cativoPct' => 'float', 'notifyEmail' => 'boolean', 'notifyWhatsapp' => 'boolean', 'active' => 'boolean'];
    }
}
