<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Parámetros de liquidación de una semana (hoja PARAMETROS del Excel de liquidaciones, 06/11/2026)
class SettlementWeekParameter extends Model
{
    use \App\Models\Concerns\Auditable;

    protected $fillable = [
        'year', 'week', 'minimum_price', 'box_type', 'bpa_rate', 'pto_bolivar_bonus', 'notes',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'week' => 'integer',
        'minimum_price' => 'decimal:2',
        'bpa_rate' => 'decimal:4',
        'pto_bolivar_bonus' => 'decimal:2',
    ];
}
