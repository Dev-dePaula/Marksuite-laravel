<?php

namespace App\Models;

class JournalEntry extends TenantModel
{
    protected $table = 'journal_entries';
    protected function casts(): array
    {
        return ['entryDate' => 'date', 'reversed' => 'boolean'];
    }
    public function lines() { return $this->hasMany(JournalLine::class, 'entryId'); }
    public function company() { return $this->belongsTo(Company::class, 'companyId'); }
}
