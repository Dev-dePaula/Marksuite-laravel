<?php

namespace App\Models;

class Opportunity extends TenantModel
{
    protected $table = 'opportunities';
    protected function casts(): array
    {
        return ['value' => 'float', 'lastContact' => 'date', 'closedAt' => 'date', 'triggerDate' => 'date', 'firstContact' => 'date', 'nextFollowup' => 'date', 'meetingAt' => 'date', 'diagnosedAt' => 'date', 'replied' => 'boolean'];
    }
    public function company() { return $this->belongsTo(Company::class, 'companyId'); }
    public function influencers() { return $this->hasMany(OppContact::class, 'opportunityId'); }
}
