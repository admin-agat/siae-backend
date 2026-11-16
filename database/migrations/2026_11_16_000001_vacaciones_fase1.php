<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 16/11/2026 — VACACIONES Y PERMISOS, FASE 1 (diseño docs/modulos/vacaciones-permisos.md, aprobado por Peter
// el 16/11/2026): catálogo de departamentos, ficha de EMPLEADOS (todo el personal, tenga o no usuario),
// feriados y kárdex de días de vacaciones (solo se agregan filas). Las solicitudes van en la fase 2.
// Cambios sobre el diseño por las respuestas de Peter:
//   - P13: departamento por catálogo (departments) y no texto libre.
//   - P14: tipo de documento (CEDULA | PASAPORTE); la cédula se valida solo si es CEDULA.
//   - El kárdex deja leave_request_id sin FK todavía (la tabla leave_requests llega en la fase 2).
// No cambia datos existentes salvo el perfil LEGAL (+ empleados_saldos).
return new class extends Migration
{
    private const PANTALLAS_LEGAL = ['empleados_saldos'];

    public function up(): void
    {
        // 1) Catálogo de departamentos (se elige con búsqueda por nombre)
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX departments_name_unique ON departments (name)');

        // 2) Ficha del personal. Tabla propia (no third_parties): las cédulas del personal solo las ve LEGAL.
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 150);                       // APELLIDOS NOMBRES
            $table->string('id_type', 10)->default('CEDULA');       // CEDULA | PASAPORTE
            $table->string('id_card', 20)->unique();                // solo LEGAL; enmascarada en la bitácora
            $table->foreignId('department_id')->constrained('departments');
            $table->string('position', 100)->nullable();            // cargo
            $table->date('hire_date');                              // de aquí salen los años de servicio
            $table->foreignId('supervisor_id')->nullable()->constrained('employees'); // jefe inmediato
            $table->foreignId('user_id')->nullable()->unique()->constrained('users'); // usuario del SIAE (opcional)
            $table->smallInteger('workweek_days')->default(5);      // 5 = lunes a viernes, 6 = hasta sábado
            $table->date('vacation_cutoff_date')->nullable();       // corte del saldo inicial
            $table->date('termination_date')->nullable();           // fecha de salida
            $table->string('notes', 300)->nullable();
            $table->boolean('status')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['department_id', 'status']);
            $table->index('supervisor_id');
        });
        DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_id_type_check CHECK (id_type IN ('CEDULA', 'PASAPORTE'))");
        DB::statement('ALTER TABLE employees ADD CONSTRAINT employees_supervisor_check CHECK (supervisor_id IS NULL OR supervisor_id <> id)');
        DB::statement('ALTER TABLE employees ADD CONSTRAINT employees_workweek_check CHECK (workweek_days IN (5, 6))');
        DB::statement('ALTER TABLE employees ADD CONSTRAINT employees_termination_check CHECK (termination_date IS NULL OR termination_date >= hire_date)');

        // 3) Feriados (LEGAL los carga cada año con el día ya trasladado por decreto)
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('holiday_date');
            $table->string('name', 100);
            $table->string('scope', 10)->default('NACIONAL');       // NACIONAL | LOCAL
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE holidays ADD CONSTRAINT holidays_scope_check CHECK (scope IN ('NACIONAL', 'LOCAL'))");
        DB::statement('CREATE UNIQUE INDEX holidays_date_activo_unique ON holidays (holiday_date) WHERE status');

        // 4) Kárdex de días: solo se agregan filas (los errores se corrigen con AJUSTE)
        Schema::create('employee_vacation_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->date('movement_date');
            $table->string('type', 14);                             // SALDO_INICIAL | GANADO | TOMADO | REVERSO | AJUSTE | PAGADO
            $table->decimal('days', 7, 3);                          // con signo
            $table->smallInteger('service_year')->nullable();       // GANADO: año de servicio
            $table->unsignedBigInteger('leave_request_id')->nullable(); // FK en la fase 2 (solicitudes)
            $table->string('reason', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users'); // null = el sistema
            $table->timestamps();
            $table->index(['employee_id', 'movement_date']);
        });
        foreach ([
            "evm_type_check CHECK (type IN ('SALDO_INICIAL', 'GANADO', 'TOMADO', 'REVERSO', 'AJUSTE', 'PAGADO'))",
            "evm_sign_check CHECK ((type IN ('GANADO', 'REVERSO') AND days > 0) OR (type IN ('TOMADO', 'PAGADO') AND days < 0) OR (type = 'AJUSTE' AND days <> 0) OR type = 'SALDO_INICIAL')",
            "evm_reason_check CHECK (type NOT IN ('AJUSTE', 'PAGADO', 'SALDO_INICIAL') OR reason IS NOT NULL)",
            "evm_service_year_check CHECK (type <> 'GANADO' OR service_year >= 1)",
        ] as $check) {
            DB::statement("ALTER TABLE employee_vacation_movements ADD CONSTRAINT $check");
        }
        // acreditar dos veces no duplica
        DB::statement("CREATE UNIQUE INDEX evm_ganado_unique ON employee_vacation_movements (employee_id, service_year) WHERE type = 'GANADO'");
        DB::statement("CREATE UNIQUE INDEX evm_saldo_inicial_unique ON employee_vacation_movements (employee_id) WHERE type = 'SALDO_INICIAL'");

        // 5) Perfil LEGAL: + Empleados y saldos (no le quita nada a nadie)
        DB::table('profiles')->where('base_role', 'LEGAL')->orderBy('id')->get()->each(function ($p) {
            $pantallas = json_decode($p->screens ?? '[]', true) ?: [];
            DB::table('profiles')->where('id', $p->id)->update([
                'screens'    => json_encode(array_values(array_unique([...$pantallas, ...self::PANTALLAS_LEGAL]))),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('profiles')->where('base_role', 'LEGAL')->orderBy('id')->get()->each(function ($p) {
            $pantallas = json_decode($p->screens ?? '[]', true) ?: [];
            DB::table('profiles')->where('id', $p->id)->update([
                'screens'    => json_encode(array_values(array_diff($pantallas, self::PANTALLAS_LEGAL))),
                'updated_at' => now(),
            ]);
        });
        Schema::dropIfExists('employee_vacation_movements');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('departments');
    }
};
