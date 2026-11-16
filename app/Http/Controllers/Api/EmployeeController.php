<?php

// EMPLEADOS Y SALDOS DE VACACIONES (LEGAL) — 16/11/2026, vacaciones fase 1
// Ubicación: app/Http/Controllers/Api/EmployeeController.php
// Todas las rutas con pantalla:empleados_saldos.
//
//   GET   /employees                 lista (cédula enmascarada) con saldo y aviso de acumulación
//   GET   /employees/options         departamentos, jefes posibles y usuarios que se pueden enlazar
//   POST  /employees                 crear (opcional: saldo inicial al corte)
//   GET   /employees/{id}            ficha completa (con cédula)
//   PUT   /employees/{id}            editar
//   PATCH /employees/{id}/deactivate { termination_date }   ·   /reactivate
//   GET   /employees/{id}/ledger     kárdex con saldo acumulado
//   POST  /employees/{id}/movements  SALDO_INICIAL o AJUSTE, con motivo
//
// Reglas: cédula ecuatoriana válida si el tipo es CEDULA; jefe activo, distinto y sin ciclos; usuario
// enlazado una sola vez y nunca uno compartido (DEPARTAMENTO) ni ADMIN. Si ya hay días acreditados,
// la fecha de ingreso y la de corte no se cambian (se corrige con un AJUSTE).

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\VacationMovement;
use App\Services\SaldoVacaciones;
use App\Support\IdentificacionEcuador;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    private const USUARIOS_NO_ENLAZABLES = ['DEPARTAMENTO', 'ADMIN'];

    public function __construct(private SaldoVacaciones $saldos)
    {
    }

    public function index(Request $request)
    {
        $empleados = Employee::with(['department:id,name', 'supervisor:id,full_name', 'user:id,name,email'])
            ->when($request->has('status'), fn ($q) => $q->where('status', $request->boolean('status')))
            ->orderBy('full_name')->get();

        return response()->json($empleados->map(fn ($e) => $this->fila($e))->values());
    }

    public function options()
    {
        $enlazados = Employee::whereNotNull('user_id')->pluck('id', 'user_id');

        return response()->json([
            'departments' => Department::where('status', true)->orderBy('name')->get(['id', 'name']),
            'supervisors' => Employee::where('status', true)->orderBy('full_name')->get(['id', 'full_name']),
            'users'       => User::where('status', true)->whereNotIn('role', self::USUARIOS_NO_ENLAZABLES)
                ->orderBy('name')->get(['id', 'name', 'email'])
                ->map(fn ($u) => $u->only(['id', 'name', 'email']) + ['employee_id' => $enlazados[$u->id] ?? null]),
        ]);
    }

    public function show($id)
    {
        $e = Employee::with(['department:id,name', 'supervisor:id,full_name', 'user:id,name,email'])->findOrFail($id);

        return response()->json($this->fila($e) + ['id_card' => $e->id_card]);
    }

    public function store(Request $request)
    {
        $datos = $this->validar($request);
        $inicial = $request->validate([
            'initial_balance' => 'nullable|numeric|min:-60|max:365',
        ], ['initial_balance.*' => 'EL SALDO INICIAL DEBE SER UN NÚMERO ENTRE -60 Y 365 DÍAS.'])['initial_balance'] ?? null;
        if ($inicial !== null && empty($datos['vacation_cutoff_date'])) {
            throw ValidationException::withMessages(['vacation_cutoff_date' => 'PARA CARGAR EL SALDO INICIAL INDICA LA FECHA DE CORTE.']);
        }

        $e = DB::transaction(function () use ($datos, $inicial) {
            $e = Employee::create($datos + ['status' => true, 'created_by' => auth()->id()]);
            if ($inicial !== null) {
                VacationMovement::create([
                    'employee_id'   => $e->id,
                    'movement_date' => $e->vacation_cutoff_date->format('Y-m-d'),
                    'type'          => 'SALDO_INICIAL',
                    'days'          => $inicial,
                    'reason'        => 'SALDO MIGRADO AL ' . $e->vacation_cutoff_date->format('d/m/Y'),
                    'created_by'    => auth()->id(),
                ]);
            }
            return $e;
        });

        return $this->show($e->id)->setStatusCode(201);
    }

    public function update(Request $request, $id)
    {
        $e = Employee::findOrFail($id);
        $datos = $this->validar($request, $e);

        $tieneGanado = $e->movements()->where('type', 'GANADO')->exists();
        $cambiaIngreso = $datos['hire_date'] !== $e->hire_date?->format('Y-m-d');
        $cambiaCorte = ($datos['vacation_cutoff_date'] ?? null) !== $e->vacation_cutoff_date?->format('Y-m-d');
        if ($tieneGanado && ($cambiaIngreso || $cambiaCorte)) {
            throw ValidationException::withMessages(['hire_date' => 'YA TIENE DÍAS ACREDITADOS: NO SE PUEDE CAMBIAR LA FECHA DE INGRESO NI LA DE CORTE. CORRIGE EL SALDO CON UN AJUSTE.']);
        }
        if ($cambiaCorte && $e->movements()->where('type', 'SALDO_INICIAL')->exists()) {
            throw ValidationException::withMessages(['vacation_cutoff_date' => 'YA TIENE SALDO INICIAL: LA FECHA DE CORTE NO SE CAMBIA. CORRIGE CON UN AJUSTE.']);
        }
        $e->update($datos);

        return $this->show($e->id);
    }

    public function deactivate(Request $request, $id)
    {
        $e = Employee::findOrFail($id);
        $fecha = $request->validate(['termination_date' => 'required|date'], ['termination_date.*' => 'INDICA LA FECHA DE SALIDA.'])['termination_date'];
        if ($fecha < $e->hire_date->format('Y-m-d')) {
            throw ValidationException::withMessages(['termination_date' => 'LA FECHA DE SALIDA NO PUEDE SER ANTES DE LA FECHA DE INGRESO.']);
        }
        if ($e->subordinates()->where('status', true)->exists()) {
            throw ValidationException::withMessages(['termination_date' => 'ES JEFE DE PERSONAL ACTIVO: ASÍGNALES OTRO JEFE ANTES DE DESACTIVARLO.']);
        }
        $e->update(['status' => false, 'termination_date' => $fecha]);

        return $this->show($e->id);
    }

    public function reactivate($id)
    {
        $e = Employee::findOrFail($id);
        $e->update(['status' => true, 'termination_date' => null]);

        return $this->show($e->id);
    }

    public function ledger($id)
    {
        $e = Employee::findOrFail($id);
        $resumen = $this->saldos->resumen($e);
        $acum = 0.0;
        $filas = $e->movements()->with('creator:id,name')->orderBy('movement_date')->orderBy('id')->get()
            ->map(function ($m) use (&$acum) {
                $acum += (float) $m->days;
                return [
                    'id'            => $m->id,
                    'movement_date' => $m->movement_date->format('Y-m-d'),
                    'type'          => $m->type,
                    'days'          => (float) $m->days,
                    'service_year'  => $m->service_year,
                    'reason'        => $m->reason,
                    'created_by'    => $m->creator?->name ?? 'SISTEMA',
                    'saldo'         => round($acum, 3),
                ];
            });

        return response()->json(['employee' => ['id' => $e->id, 'full_name' => $e->full_name], 'resumen' => $resumen, 'movements' => $filas]);
    }

    public function addMovement(Request $request, $id)
    {
        $e = Employee::findOrFail($id);
        $datos = $request->validate([
            'type'          => 'required|in:' . implode(',', VacationMovement::MANUALES),
            'days'          => 'required|numeric|min:-365|max:365',
            'movement_date' => 'nullable|date',
            'reason'        => 'required|string|min:5|max:300',
        ], [
            'type.*'          => 'ELIGE SALDO INICIAL O AJUSTE.',
            'days.*'          => 'LOS DÍAS DEBEN SER UN NÚMERO ENTRE -365 Y 365.',
            'movement_date.*' => 'LA FECHA NO ES VÁLIDA.',
            'reason.*'        => 'ESCRIBE EL MOTIVO (AL MENOS 5 LETRAS).',
        ]);
        $hoy = now(config('app.zona_operacion'))->format('Y-m-d');

        return DB::transaction(function () use ($e, $datos, $hoy) {
            Employee::whereKey($e->id)->lockForUpdate()->first();
            if ($datos['type'] === 'AJUSTE') {
                if ((float) $datos['days'] == 0) {
                    throw ValidationException::withMessages(['days' => 'EL AJUSTE NO PUEDE SER DE 0 DÍAS.']);
                }
                $fecha = $hoy;
            } else {
                // Saldo inicial: una sola vez; su fecha es el corte y los aniversarios anteriores ya van incluidos
                $fecha = $datos['movement_date'] ?? null;
                if (!$fecha || $fecha > $hoy || $fecha < $e->hire_date->format('Y-m-d')) {
                    throw ValidationException::withMessages(['movement_date' => 'INDICA LA FECHA DE CORTE DEL SALDO (ENTRE EL INGRESO Y HOY).']);
                }
                if ($e->movements()->where('type', 'SALDO_INICIAL')->exists()) {
                    throw ValidationException::withMessages(['type' => 'YA TIENE SALDO INICIAL. PARA CORREGIRLO USA UN AJUSTE.']);
                }
                if ($e->movements()->where('type', 'GANADO')->where('movement_date', '<=', $fecha)->exists()) {
                    throw ValidationException::withMessages(['movement_date' => 'YA HAY DÍAS ACREDITADOS ANTES DE ESE CORTE. USA UN AJUSTE O ELIGE UN CORTE ANTERIOR AL PRIMER ANIVERSARIO ACREDITADO.']);
                }
                $e->update(['vacation_cutoff_date' => $fecha]);
            }
            VacationMovement::create([
                'employee_id'   => $e->id,
                'movement_date' => $fecha,
                'type'          => $datos['type'],
                'days'          => round((float) $datos['days'], 3),
                'reason'        => mb_strtoupper(trim($datos['reason'])),
                'created_by'    => auth()->id(),
            ]);

            return $this->ledger($e->id)->setStatusCode(201);
        });
    }

    // ---------------------------------------------------------------- internos

    private function fila(Employee $e): array
    {
        return [
            'id'                   => $e->id,
            'full_name'            => $e->full_name,
            'id_type'              => $e->id_type,
            'id_card_masked'       => AuditLog::enmascarar($e->id_card),
            'department_id'        => $e->department_id,
            'department'           => $e->department?->name,
            'position'             => $e->position,
            'hire_date'            => $e->hire_date?->format('Y-m-d'),
            'supervisor_id'        => $e->supervisor_id,
            'supervisor'           => $e->supervisor?->full_name,
            'user_id'              => $e->user_id,
            'user'                 => $e->user ? ['id' => $e->user->id, 'name' => $e->user->name, 'email' => $e->user->email] : null,
            'workweek_days'        => $e->workweek_days,
            'vacation_cutoff_date' => $e->vacation_cutoff_date?->format('Y-m-d'),
            'termination_date'     => $e->termination_date?->format('Y-m-d'),
            'notes'                => $e->notes,
            'status'               => $e->status,
        ] + $this->saldos->resumen($e);
    }

    private function validar(Request $request, ?Employee $actual = null): array
    {
        foreach (['full_name', 'id_card', 'position', 'notes'] as $c) {
            if (is_string($request->input($c))) {
                $request->merge([$c => mb_strtoupper(trim($request->input($c)))]);
            }
        }
        $hoy = now(config('app.zona_operacion'))->format('Y-m-d');
        $datos = $request->validate([
            'full_name'            => 'required|string|min:5|max:150',
            'id_type'              => 'required|in:' . implode(',', Employee::ID_TYPES),
            'id_card'              => ['required', 'string', 'max:20', Rule::unique('employees', 'id_card')->ignore($actual?->id)],
            'department_id'        => ['required', 'integer', Rule::exists('departments', 'id')->where('status', true)],
            'position'             => 'nullable|string|max:100',
            'hire_date'            => 'required|date|before_or_equal:' . $hoy,
            'supervisor_id'        => ['nullable', 'integer', Rule::exists('employees', 'id')->where('status', true)],
            'user_id'              => ['nullable', 'integer', Rule::exists('users', 'id')->where('status', true), Rule::unique('employees', 'user_id')->ignore($actual?->id)],
            'workweek_days'        => 'required|in:5,6',
            'vacation_cutoff_date' => 'nullable|date|before_or_equal:' . $hoy,
            'notes'                => 'nullable|string|max:300',
        ], [
            'full_name.*'            => 'ESCRIBE LOS APELLIDOS Y NOMBRES (HASTA 150 LETRAS).',
            'id_type.*'              => 'ELIGE EL TIPO DE DOCUMENTO: CÉDULA O PASAPORTE.',
            'id_card.unique'         => 'ESA CÉDULA O PASAPORTE YA ESTÁ REGISTRADO EN OTRO EMPLEADO.',
            'id_card.*'              => 'ESCRIBE LA CÉDULA O EL PASAPORTE.',
            'department_id.*'        => 'ELIGE EL DEPARTAMENTO.',
            'position.*'             => 'EL CARGO PUEDE TENER HASTA 100 LETRAS.',
            'hire_date.*'            => 'INDICA LA FECHA DE INGRESO (NO PUEDE SER FUTURA).',
            'supervisor_id.*'        => 'EL JEFE INMEDIATO DEBE SER UN EMPLEADO ACTIVO.',
            'user_id.unique'         => 'ESE USUARIO YA ESTÁ ENLAZADO A OTRO EMPLEADO.',
            'user_id.*'              => 'EL USUARIO ELEGIDO NO EXISTE O ESTÁ INACTIVO.',
            'workweek_days.*'        => 'ELIGE SI TRABAJA 5 O 6 DÍAS A LA SEMANA.',
            'vacation_cutoff_date.*' => 'LA FECHA DE CORTE NO ES VÁLIDA (NO PUEDE SER FUTURA).',
            'notes.*'                => 'LAS NOTAS PUEDEN TENER HASTA 300 LETRAS.',
        ]);

        if ($datos['id_type'] === 'CEDULA') {
            $error = strlen($datos['id_card']) !== 10 ? 'La cédula debe tener 10 dígitos.' : IdentificacionEcuador::error($datos['id_card']);
            if ($error) {
                throw ValidationException::withMessages(['id_card' => mb_strtoupper($error)]);
            }
        } elseif (!preg_match('/^[A-Z0-9]{5,20}$/', $datos['id_card'])) {
            throw ValidationException::withMessages(['id_card' => 'EL PASAPORTE DEBE TENER DE 5 A 20 LETRAS O NÚMEROS, SIN ESPACIOS.']);
        }
        if (!empty($datos['vacation_cutoff_date']) && $datos['vacation_cutoff_date'] < $datos['hire_date']) {
            throw ValidationException::withMessages(['vacation_cutoff_date' => 'LA FECHA DE CORTE NO PUEDE SER ANTES DE LA FECHA DE INGRESO.']);
        }
        if (!empty($datos['user_id']) && in_array(User::whereKey($datos['user_id'])->value('role'), self::USUARIOS_NO_ENLAZABLES, true)) {
            throw ValidationException::withMessages(['user_id' => 'NO SE PUEDE ENLAZAR UN USUARIO COMPARTIDO (DEPARTAMENTO) NI UN ADMIN.']);
        }
        // Jefe: distinto de sí mismo y sin ciclos (subiendo por la cadena nunca se vuelve a él)
        if (!empty($datos['supervisor_id']) && $actual) {
            $visto = [];
            for ($j = (int) $datos['supervisor_id']; $j; $j = (int) Employee::whereKey($j)->value('supervisor_id')) {
                if ($j === $actual->id) {
                    throw ValidationException::withMessages(['supervisor_id' => 'ESE JEFE DEPENDE DE ESTA PERSONA: SE FORMARÍA UN CÍRCULO. ELIGE OTRO JEFE.']);
                }
                if (isset($visto[$j])) {
                    break;
                }
                $visto[$j] = true;
            }
        }

        return $datos;
    }
}
