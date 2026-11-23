<?php

namespace App\Models;

class Notification extends TenantModel
{
    protected $table = 'notifications';
    protected function casts(): array
    {
        return ['sentAt' => 'datetime', 'manual' => 'boolean'];
    }
    public function invoice() { return $this->belongsTo(Invoice::class, 'invoiceId'); }
}
