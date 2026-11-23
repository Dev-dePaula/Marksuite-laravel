<?php

namespace App\Models;

class Objective extends TenantModel
{
    protected $table = 'objectives';
    protected function casts(): array
    {
        return [];
    }
    public function keyResults() { return $this->hasMany(KeyResult::class, 'objectiveId'); }
}
