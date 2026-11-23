<?php

namespace App\Models;

class Prospect extends TenantModel
{
    protected $table = 'prospects';
    protected function casts(): array
    {
        return ['approved' => 'boolean', 'optOut' => 'boolean'];
    }
    public function run() { return $this->belongsTo(ProspectRun::class, 'runId'); }
}
