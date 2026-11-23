<?php

namespace App\Models\Concerns;

use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;

/**
 * Isolamento multi-empresa: toda a leitura é filtrada pelo tenant activo e toda a criação é
 * carimbada com ele. Sem tenant activo (ex.: registo, comandos) o filtro não se aplica.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $q) {
            if (($id = Tenancy::id()) !== null) {
                $q->where($q->getModel()->getTable() . '.tenantId', $id);
            }
        });
        static::creating(function ($m) {
            if (empty($m->tenantId) && Tenancy::id() !== null) $m->tenantId = Tenancy::id();
        });
    }
}
