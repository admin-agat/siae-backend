<?php

// 16/11/2026: kárdex de días de vacaciones. Solo se agregan filas: un error se corrige con AJUSTE
// (o REVERSO en la fase 2). GANADO lo acredita el sistema (App\Services\SaldoVacaciones).

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VacationMovement extends Model
{
    use \App\Models\Concerns\Auditable;

    protected $table = 'employee_vacation_movements';

    public const TYPES = ['SALDO_INICIAL', 'GANADO', 'TOMADO', 'REVERSO', 'AJUSTE', 'PAGADO'];

    /** Tipos que LEGAL registra a mano (con motivo) */
    public const MANUALES = ['SALDO_INICIAL', 'AJUSTE'];

    protected $fillable = ['employee_id', 'movement_date', 'type', 'days', 'service_year', 'leave_request_id', 'reason', 'created_by'];

    protected $casts = [
        'movement_date' => 'date:Y-m-d',
        'days'          => 'decimal:3',
        'service_year'  => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
