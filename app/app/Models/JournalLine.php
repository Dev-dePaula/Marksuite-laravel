<?php

namespace App\Models;

class JournalLine extends TenantModel
{
    protected $table = 'journal_lines';
    protected function casts(): array
    {
        return ['debit' => 'float', 'credit' => 'float'];
    }
    public function account() { return $this->belongsTo(Account::class, 'accountId'); }
    public function entry() { return $this->belongsTo(JournalEntry::class, 'entryId'); }
}
