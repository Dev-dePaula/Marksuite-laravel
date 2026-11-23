<?php

namespace App\Models;

class Receipt extends TenantModel
{
    protected $table = 'receipts';
    protected function casts(): array
    {
        return ['amount' => 'float', 'lateAmount' => 'float', 'paymentDate' => 'date'];
    }
    public function invoice() { return $this->belongsTo(Invoice::class, 'invoiceId'); }
}
