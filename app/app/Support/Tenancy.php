<?php

namespace App\Support;

/** Tenant (empresa-cliente) activo no pedido actual. */
class Tenancy
{
    private static ?int $id = null;

    public static function set(?int $id): void { self::$id = $id; }
    public static function id(): ?int { return self::$id; }
}
