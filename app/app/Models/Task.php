<?php

namespace App\Models;

class Task extends TenantModel
{
    protected $table = 'tasks';
    protected function casts(): array
    {
        return ['dueDate' => 'date', 'done' => 'boolean'];
    }
    public function assignee() { return $this->belongsTo(Employee::class, 'assigneeId'); }
}
