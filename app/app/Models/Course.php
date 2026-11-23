<?php

namespace App\Models;

class Course extends TenantModel
{
    protected $table = 'courses';
    protected function casts(): array
    {
        return [];
    }
    public function enrollments() { return $this->hasMany(Enrollment::class, 'courseId'); }
}
