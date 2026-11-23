<?php

namespace App\Models;

class Project extends TenantModel
{
    protected $table = 'projects';
    protected function casts(): array
    {
        return ['startDate' => 'date', 'dueDate' => 'date', 'completedAt' => 'date'];
    }
    public function company() { return $this->belongsTo(Company::class, 'companyId'); }
    public function owner() { return $this->belongsTo(Employee::class, 'ownerId'); }
}
