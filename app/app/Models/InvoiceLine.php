<?php

namespace App\Models;

class InvoiceLine extends TenantModel
{
    protected $table = 'invoice_lines';
    protected function casts(): array
    {
        return ['qty' => 'float', 'price' => 'float', 'discPct' => 'float', 'ivaRate' => 'float', 'net' => 'float', 'tax' => 'float'];
    }
    public function invoice() { return $this->belongsTo(Invoice::class, 'invoiceId'); }
}
