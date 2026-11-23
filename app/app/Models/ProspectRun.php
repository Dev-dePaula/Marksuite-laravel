<?php

namespace App\Models;

class ProspectRun extends TenantModel
{
    protected $table = 'prospect_runs';
    public function company() { return $this->belongsTo(Company::class, 'companyId'); }
    public function prospects() { return $this->hasMany(Prospect::class, 'runId'); }
}
