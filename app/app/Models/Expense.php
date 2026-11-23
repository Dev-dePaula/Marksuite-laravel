<?php

namespace App\Models;

class Expense extends TenantModel
{
    protected $table = 'expenses';
    protected function casts(): array
    {
        return ['subtotal' => 'float', 'ivaRate' => 'float', 'ivaAmount' => 'float', 'amount' => 'float', 'dueDate' => 'date', 'decidedAt' => 'datetime', 'paidAt' => 'datetime'];
    }
    public function company() { return $this->belongsTo(Company::class, 'companyId'); }
    public function supplier() { return $this->belongsTo(Supplier::class, 'supplierId'); }
}
