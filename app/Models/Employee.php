<?php

// 16/11/2026: ficha del personal (vacaciones fase 1). Tabla propia, no third_parties: la cédula
// solo la ve LEGAL (pantalla empleados_saldos) y en la bitácora queda enmascarada (****1234).
// El jefe inmediato va en supervisor_id (no confundir con users.boss_id = "asistente de").

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use \App\Models\Concerns\Auditable;

    public const ID_TYPES = ['CEDULA', 'PASAPORTE'];

    protected $fillable = [
        'full_name', 'id_type', 'id_card', 'department_id', 'position', 'hire_date', 'supervisor_id', 'user_id',
        'workweek_days', 'vacation_cutoff_date', 'termination_date', 'notes', 'status', 'created_by',
    ];

    protected $casts = [
        'hire_date'            => 'date:Y-m-d',
        'vacation_cutoff_date' => 'date:Y-m-d',
        'termination_date'     => 'date:Y-m-d',
        'status'               => 'boolean',
        'workweek_days'        => 'integer',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function supervisor()
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function subordinates()
    {
        return $this->hasMany(Employee::class, 'supervisor_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function movements()
    {
        return $this->hasMany(VacationMovement::class);
    }
}
