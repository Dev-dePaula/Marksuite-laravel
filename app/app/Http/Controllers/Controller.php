<?php

namespace App\Http\Controllers;

use App\Support\Tenancy;
use Illuminate\Validation\Rule;

abstract class Controller
{
    /** Regra "exists" restrita ao tenant activo: impede referenciar registos de outras empresas-clientes. */
    protected function ex(string $table, string $col = 'id')
    {
        return Rule::exists($table, $col)->where('tenantId', Tenancy::id());
    }
}
