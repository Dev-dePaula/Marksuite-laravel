<?php

namespace App\Models;

class Process extends TenantModel
{
    protected $table = 'processes';
    protected function casts(): array
    {
        return [];
    }
    public function company() { return $this->belongsTo(Company::class, 'companyId'); }
    public function owner() { return $this->belongsTo(Employee::class, 'ownerId'); }
    public function actions() { return $this->hasMany(ProcessAction::class, 'processId'); }
}
