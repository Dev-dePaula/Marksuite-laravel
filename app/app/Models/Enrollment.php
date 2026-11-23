<?php

namespace App\Models;

class Enrollment extends TenantModel
{
    protected $table = 'enrollments';
    protected function casts(): array
    {
        return [];
    }
}
