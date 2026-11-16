<?php

// 16/11/2026: feriados (LEGAL los carga cada año con el día ya trasladado por decreto). Vacaciones fase 1.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use \App\Models\Concerns\Auditable;

    public const SCOPES = ['NACIONAL', 'LOCAL'];

    protected $fillable = ['holiday_date', 'name', 'scope', 'status'];

    protected $casts = [
        'holiday_date' => 'date:Y-m-d',
        'status'       => 'boolean',
    ];
}
