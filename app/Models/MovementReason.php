<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MovementReason extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    // Columnas que se pueden asignar masivamente (create()/update() con arrays).
    protected $fillable = [
        'name',
        'type',
        'status',
    ];

    // Laravel intenta castear 'status' automáticamente por ser boolean en la
    // migración, pero lo dejamos explícito para evitar sorpresas como con
    // otros catálogos del módulo Inventario.
    protected $casts = [
        'status' => 'boolean',
    ];
}