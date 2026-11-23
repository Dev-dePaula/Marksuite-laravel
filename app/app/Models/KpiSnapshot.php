<?php

namespace App\Models;

class KpiSnapshot extends TenantModel
{
    protected $table = 'kpi_snapshots';
    protected function casts(): array
    {
        // `day` fica como texto Y-m-d (chave única diária); só `value` é convertido.
        return ['value' => 'float'];
    }
}
