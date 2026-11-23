<?php

namespace App\Models;

class ProcessAction extends TenantModel
{
    protected $table = 'process_actions';
    protected function casts(): array
    {
        return ['dueDate' => 'date', 'done' => 'boolean'];
    }
    public function process() { return $this->belongsTo(Process::class, 'processId'); }
    public function owner() { return $this->belongsTo(Employee::class, 'ownerId'); }
}
