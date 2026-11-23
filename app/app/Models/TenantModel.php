<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

abstract class TenantModel extends Model
{
    use BelongsToTenant;

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    /** O JSON da API usa os mesmos nomes (camelCase) do backend original. */
    public static $snakeAttributes = false;

    protected $guarded = [];
    protected $hidden = ['tenantId', 'updatedAt'];
}
