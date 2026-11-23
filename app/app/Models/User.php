<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class User extends Model implements \Illuminate\Contracts\Auth\Authenticatable
{
    use HasApiTokens, \Illuminate\Auth\Authenticatable;

    public static $snakeAttributes = false;
    protected $guarded = [];
    protected $hidden = ['passwordHash'];
    protected $casts = ['active' => 'boolean', 'isOwner' => 'boolean'];

    public function getAuthPassword() { return $this->passwordHash; }
    public function getAuthPasswordName() { return 'passwordHash'; }
    public function tenant() { return $this->belongsTo(Tenant::class, 'tenantId'); }
}
