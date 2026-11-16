<?php

// 16/11/2026: catálogo de departamentos (vacaciones fase 1; se elige con búsqueda en la ficha de empleados)

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use \App\Models\Concerns\Auditable;

    protected $fillable = ['name', 'status'];

    protected $casts = ['status' => 'boolean'];

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }
}
