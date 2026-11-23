<?php

namespace App\Models;

class Invoice extends TenantModel
{
    protected $table = 'invoices';
    protected function casts(): array
    {
        return ['amount' => 'float', 'subtotal' => 'float', 'ivaRate' => 'float', 'ivaAmount' => 'float', 'paidAmount' => 'float', 'gdiscPct' => 'float', 'retPct' => 'float', 'retAmount' => 'float', 'cativoPct' => 'float', 'cativoAmount' => 'float', 'grossTotal' => 'float', 'dueDate' => 'date', 'paidAt' => 'date', 'issueDate' => 'date', 'cancelledAt' => 'datetime', 'lastNotifiedAt' => 'datetime', 'draft' => 'boolean', 'archived' => 'boolean', 'transport' => 'array', 'delivery' => 'array'];
    }
    public function company() { return $this->belongsTo(Company::class, 'companyId'); }
    public function contact() { return $this->belongsTo(Contact::class, 'contactId'); }
    public function lines() { return $this->hasMany(InvoiceLine::class, 'invoiceId'); }
    public function receipts() { return $this->hasMany(Receipt::class, 'invoiceId'); }
    public function notifications() { return $this->hasMany(Notification::class, 'invoiceId'); }
}
