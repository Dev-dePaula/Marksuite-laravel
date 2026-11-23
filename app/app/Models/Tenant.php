<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $guarded = [];
    protected $casts = ['active' => 'boolean', 'trialEndsAt' => 'datetime'];
}
