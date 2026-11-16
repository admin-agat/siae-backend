<?php

// 16/11/2026: pruebas de VACACIONES Y PERMISOS, fase 1 (empleados, kárdex, departamentos, feriados).
// Base: siae_test con DatabaseTransactions (nunca RefreshDatabase). Cada prueba arma sus datos.

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Profile;
use App\Models\User;
use App\Models\VacationMovement;
use App\Services\SaldoVacaciones;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VacacionesFase1Test extends TestCase
{
    use DatabaseTransactions;

    private static int $n = 0;
    private string $k;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('siae_test', DB::connection()->getDatabaseName());
        $this->k = ++self::$n . strtoupper(substr(uniqid(), -5));
    }

    // ------------------------------------------------------------ ayudas

    private function hoy(): \Carbon\Carbon
    {
        return now(config('app.zona_operacion'))->startOfDay();
    }

    private function usuario(string $rol = 'LEGAL', ?array $pantallas = ['empleados_saldos']): User
    {
        $perfil = $rol === 'ADMIN' ? null : Profile::create(['name' => "PERFIL $rol {$this->k}" . uniqid(), 'base_role' => $rol, 'screens' => $pantallas ?? [], 'status' => true]);

        return User::create(['name' => "USUARIO $rol", 'email' => 'u' . strtolower(uniqid()) . '@test.local', 'password' => 'Clave-Test-123',
            'role' => $rol, 'status' => true, 'must_change_password' => false, 'password_changed_at' => now(), 'profile_id' => $perfil?->id]);
    }

    private function legal(): User
    {
        $u = $this->usuario();
        Sanctum::actingAs($u);

        return $u;
    }

    private function depto(?string $nombre = null): Department
    {
        return Department::create(['name' => $nombre ?? 'DEPTO ' . $this->k . uniqid(), 'status' => true]);
    }

    /** Cédula ecuatoriana válida (módulo 10) distinta cada vez */
    private function cedula(): string
    {
        $base = '17' . str_pad((string) random_int(0, 4999999), 7, '0', STR_PAD_LEFT);   // tercer dígito 0..4
        $suma = 0;
        for ($i = 0; $i < 9; $i++) {
            $v = (int) $base[$i] * ($i % 2 === 0 ? 2 : 1);
            $suma += $v > 9 ? $v - 9 : $v;
        }

        return $base . ((10 - $suma % 10) % 10);
    }

    private function empleado(array $o = []): Employee
    {
        return Employee::create($o + [
            'full_name' => 'PEREZ JUAN ' . uniqid(), 'id_type' => 'PASAPORTE', 'id_card' => 'P' . strtoupper(uniqid()),
            'department_id' => $this->depto()->id, 'hire_date' => $this->hoy()->subYears(2)->subDays(10)->format('Y-m-d'),
            'workweek_days' => 5, 'status' => true,
        ]);
    }

    private function payload(array $o = []): array
    {
        return $o + [
            'full_name' => 'Gomez Ana ' . $this->k, 'id_type' => 'CEDULA', 'id_card' => $this->cedula(),
            'department_id' => $this->depto()->id, 'position' => 'ASISTENTE',
            'hire_date' => $this->hoy()->subYear()->subDays(30)->format('Y-m-d'), 'workweek_days' => 5,
        ];
    }

    // ------------------------------------------------------------ 1. días por año

    public function test_dias_por_anio_1_a_21(): void
    {
        $esperado = [1 => 15, 2 => 15, 3 => 15, 4 => 15, 5 => 16, 6 => 17, 7 => 18, 8 => 19, 9 => 20, 10 => 21, 11 => 22,
            12 => 23, 13 => 24, 14 => 25, 15 => 26, 16 => 27, 17 => 28, 18 => 29, 19 => 30, 20 => 30, 21 => 30];
        $s = new SaldoVacaciones();
        foreach ($esperado as $n => $dias) {
            $this->assertSame($dias, $s->diasPorAnio($n), "año $n");
        }
    }

    // ------------------------------------------------------------ 2. acreditar

    public function test_acreditar_pendientes_y_no_duplica(): void
    {
        $e = $this->empleado(['hire_date' => $this->hoy()->subYears(3)->subDays(5)->format('Y-m-d')]);
        $s = new SaldoVacaciones();
        $this->assertSame(3, $s->acreditarPendientes($e));
        $this->assertSame(0, $s->acreditarPendientes($e->fresh()));
        $this->assertSame(3, $e->movements()->where('type', 'GANADO')->count());
        $this->assertEquals(45, $e->movements()->sum('days'));
    }

    public function test_acreditar_no_incluye_aniversario_de_manana_pero_si_el_de_hoy(): void
    {
        $manana = $this->empleado(['hire_date' => $this->hoy()->subYear()->addDay()->format('Y-m-d')]);
        $hoy = $this->empleado(['hire_date' => $this->hoy()->subYear()->format('Y-m-d')]);
        $s = new SaldoVacaciones();
        $this->assertSame(0, $s->acreditarPendientes($manana));
        $this->assertSame(1, $s->acreditarPendientes($hoy));
    }

    public function test_acreditar_respeta_corte_y_salida(): void
    {
        $s = new SaldoVacaciones();
        // ingreso hace 5 años y 10 días; corte hace 2 años y 100 días: quedan aniversarios 3,4,5 solo si > corte
        $hire = $this->hoy()->subYears(5)->subDays(10);
        $corte = $this->hoy()->subYears(2)->subDays(100);   // aniversarios 1,2 (y 3 = hire+3y = hoy-2y-10d > corte) → 3,4,5
        $conCorte = $this->empleado(['hire_date' => $hire->format('Y-m-d'), 'vacation_cutoff_date' => $corte->format('Y-m-d')]);
        $this->assertSame(3, $s->acreditarPendientes($conCorte));
        $this->assertSame([3, 4, 5], $conCorte->movements()->orderBy('service_year')->pluck('service_year')->all());

        // salida: a los 2 años y 6 meses → solo aniversarios 1 y 2
        $salida = $this->empleado(['hire_date' => $hire->format('Y-m-d'), 'status' => false,
            'termination_date' => $hire->copy()->addYears(2)->addMonths(6)->format('Y-m-d')]);
        $this->assertSame(2, $s->acreditarPendientes($salida));

    }

    // Borde: corte o salida EN el día del aniversario (son fechas puras; el aniversario trae hora Ecuador)
    public function test_acreditar_borde_corte_igual_al_aniversario(): void
    {
        $s = new SaldoVacaciones();
        $hire = $this->hoy()->subYears(5)->subDays(10);
        $igual = $this->empleado(['hire_date' => $hire->format('Y-m-d'), 'vacation_cutoff_date' => $hire->copy()->addYears(2)->format('Y-m-d')]);
        $this->assertSame(3, $s->acreditarPendientes($igual), 'el aniversario igual al corte ya viene dentro del saldo inicial');
        $this->assertSame([3, 4, 5], $igual->movements()->orderBy('service_year')->pluck('service_year')->all());
    }

    public function test_acreditar_borde_salida_el_dia_del_aniversario(): void
    {
        $s = new SaldoVacaciones();
        $hire = $this->hoy()->subYears(5)->subDays(10);
        $salida = $this->empleado(['hire_date' => $hire->format('Y-m-d'), 'status' => false, 'termination_date' => $hire->copy()->addYears(2)->format('Y-m-d')]);
        $this->assertSame(2, $s->acreditarPendientes($salida), 'cumplió el 2.º año el mismo día de su salida: se acredita');
    }

    // ------------------------------------------------------------ 3. saldo FIFO

    public function test_saldo_y_acumulado_viejo_fifo(): void
    {
        $hire = $this->hoy()->subYears(6);
        // termination = hire: el servicio no acredita nada más; los lotes se arman a mano
        $e = $this->empleado(['hire_date' => $hire->format('Y-m-d'), 'status' => false, 'termination_date' => $hire->format('Y-m-d')]);
        $mov = fn (string $t, float $d, $fecha, array $x = []) => VacationMovement::create(['employee_id' => $e->id, 'type' => $t, 'days' => $d,
            'movement_date' => $fecha->format('Y-m-d'), 'reason' => 'PRUEBA FIFO'] + $x);
        $mov('SALDO_INICIAL', 20, $this->hoy()->subYears(5));
        $mov('GANADO', 15, $this->hoy()->subYears(4), ['service_year' => 1]);
        $mov('GANADO', 15, $this->hoy()->subYear(), ['service_year' => 2]);

        $s = new SaldoVacaciones();
        $r = $s->resumen($e->fresh());
        $this->assertEquals(50, $r['saldo']);
        $this->assertEquals(35, $r['acumulado_viejo']);

        $mov('AJUSTE', -25, $this->hoy());   // consume 20 del inicial + 5 del año 1 → quedan 10 viejos
        $r = $s->resumen($e->fresh());
        $this->assertEquals(25, $r['saldo']);
        $this->assertEquals(10, $r['acumulado_viejo']);

        $mov('AJUSTE', -10, $this->hoy());   // ya van 35 gastados: se acaba lo viejo y no toca lo reciente
        $r = $s->resumen($e->fresh());
        $this->assertEquals(15, $r['saldo']);
        $this->assertEquals(0, $r['acumulado_viejo']);
    }

    // ------------------------------------------------------------ 4. empleados

    public function test_crear_empleado_con_cedula_valida(): void
    {
        $this->legal();
        $r = $this->postJson('/api/employees', $this->payload());
        $r->assertStatus(201);
        $r->assertJsonPath('full_name', 'GOMEZ ANA ' . $this->k);
        $this->assertNotEmpty($r->json('id_card'));
    }

    public function test_cedula_invalida_digito_verificador_422(): void
    {
        $this->legal();
        $c = $this->cedula();
        $mala = substr($c, 0, 9) . (((int) $c[9] + 1) % 10);
        $this->postJson('/api/employees', $this->payload(['id_card' => $mala]))->assertStatus(422)->assertJsonValidationErrors('id_card');
        $this->postJson('/api/employees', $this->payload(['id_card' => '123']))->assertStatus(422)->assertJsonValidationErrors('id_card');
    }

    public function test_pasaporte_valido_e_invalido(): void
    {
        $this->legal();
        $this->postJson('/api/employees', $this->payload(['id_type' => 'PASAPORTE', 'id_card' => 'ab123456']))->assertStatus(201);
        $this->postJson('/api/employees', $this->payload(['id_type' => 'PASAPORTE', 'id_card' => 'AB 12']))->assertStatus(422)->assertJsonValidationErrors('id_card');
    }

    public function test_cedula_duplicada_422(): void
    {
        $this->legal();
        $c = $this->cedula();
        $this->postJson('/api/employees', $this->payload(['id_card' => $c]))->assertStatus(201);
        $this->postJson('/api/employees', $this->payload(['id_card' => $c]))->assertStatus(422)->assertJsonValidationErrors('id_card');
    }

    public function test_editar_empleado(): void
    {
        $this->legal();
        $id = $this->postJson('/api/employees', $this->payload())->json('id');
        $e = Employee::find($id);
        $this->putJson("/api/employees/$id", $this->payload(['id_card' => $e->id_card, 'department_id' => $e->department_id,
            'hire_date' => $e->hire_date->format('Y-m-d'), 'position' => 'jefe de area']))
            ->assertOk()->assertJsonPath('position', 'JEFE DE AREA');
    }

    public function test_user_id_de_departamento_o_admin_422(): void
    {
        $this->legal();
        foreach (['DEPARTAMENTO', 'ADMIN'] as $rol) {
            $u = $this->usuario($rol);
            $this->postJson('/api/employees', $this->payload(['user_id' => $u->id]))->assertStatus(422)->assertJsonValidationErrors('user_id');
        }
    }

    public function test_user_id_ya_enlazado_422_y_options_lo_marca(): void
    {
        $this->legal();
        $u = $this->usuario('COMERCIAL', ['pedido_semanal']);
        $this->postJson('/api/employees', $this->payload(['user_id' => $u->id]))->assertStatus(201);
        $this->postJson('/api/employees', $this->payload(['user_id' => $u->id]))->assertStatus(422)->assertJsonValidationErrors('user_id');
        $op = collect($this->getJson('/api/employees/options')->assertOk()->json('users'))->firstWhere('id', $u->id);
        $this->assertNotNull($op['employee_id']);
    }

    public function test_ciclo_de_jefes_422(): void
    {
        $this->legal();
        $a = $this->empleado();
        $b = $this->empleado(['supervisor_id' => $a->id]);
        $this->putJson("/api/employees/{$a->id}", [
            'full_name' => $a->full_name, 'id_type' => 'PASAPORTE', 'id_card' => $a->id_card, 'department_id' => $a->department_id,
            'hire_date' => $a->hire_date->format('Y-m-d'), 'workweek_days' => 5, 'supervisor_id' => $b->id,
        ])->assertStatus(422)->assertJsonValidationErrors('supervisor_id');
    }

    public function test_cambiar_hire_date_con_ganado_422(): void
    {
        $this->legal();
        $e = $this->empleado();
        $this->getJson("/api/employees/{$e->id}")->assertOk();   // acredita
        $this->assertTrue($e->movements()->where('type', 'GANADO')->exists());
        $this->putJson("/api/employees/{$e->id}", [
            'full_name' => $e->full_name, 'id_type' => 'PASAPORTE', 'id_card' => $e->id_card, 'department_id' => $e->department_id,
            'hire_date' => $this->hoy()->subYears(2)->subDays(20)->format('Y-m-d'), 'workweek_days' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors('hire_date');
    }

    public function test_deactivate_sin_fecha_y_con_subordinados_422(): void
    {
        $this->legal();
        $jefe = $this->empleado();
        $sub = $this->empleado(['supervisor_id' => $jefe->id]);
        $this->patchJson("/api/employees/{$sub->id}/deactivate", [])->assertStatus(422)->assertJsonValidationErrors('termination_date');
        $this->patchJson("/api/employees/{$jefe->id}/deactivate", ['termination_date' => $this->hoy()->format('Y-m-d')])
            ->assertStatus(422)->assertJsonValidationErrors('termination_date');
        // sin subordinado activo sí se puede, y no se borra
        $this->patchJson("/api/employees/{$sub->id}/deactivate", ['termination_date' => $this->hoy()->format('Y-m-d')])->assertOk();
        $this->assertFalse((bool) Employee::find($sub->id)->status);
        $this->patchJson("/api/employees/{$jefe->id}/deactivate", ['termination_date' => $this->hoy()->format('Y-m-d')])->assertOk();
        $this->patchJson("/api/employees/{$jefe->id}/reactivate")->assertOk();
        $this->assertTrue((bool) Employee::find($jefe->id)->status);
        $this->assertNull(Employee::find($jefe->id)->termination_date);
    }

    public function test_lista_no_trae_cedula_completa_y_show_si(): void
    {
        $this->legal();
        $e = $this->empleado(['id_type' => 'CEDULA', 'id_card' => $this->cedula()]);
        $lista = $this->getJson('/api/employees')->assertOk();
        $fila = collect($lista->json())->firstWhere('id', $e->id);
        $this->assertArrayNotHasKey('id_card', $fila);
        $this->assertStringNotContainsString($e->id_card, $lista->getContent());
        $this->assertArrayHasKey('id_card_masked', $fila);
        $this->getJson("/api/employees/{$e->id}")->assertOk()->assertJsonPath('id_card', $e->id_card);
    }

    // ------------------------------------------------------------ 5. movimientos

    public function test_saldo_inicial_una_vez_fija_corte(): void
    {
        $this->legal();
        $e = $this->empleado(['hire_date' => $this->hoy()->subYears(3)->format('Y-m-d')]);
        $corte = $this->hoy()->subMonths(2)->format('Y-m-d');
        $this->postJson("/api/employees/{$e->id}/movements", ['type' => 'SALDO_INICIAL', 'days' => 12.5, 'movement_date' => $corte, 'reason' => 'saldo migrado'])
            ->assertStatus(201);
        $this->assertSame($corte, $e->fresh()->vacation_cutoff_date->format('Y-m-d'));
        $this->postJson("/api/employees/{$e->id}/movements", ['type' => 'SALDO_INICIAL', 'days' => 3, 'movement_date' => $corte, 'reason' => 'otra vez'])
            ->assertStatus(422)->assertJsonValidationErrors('type');
        $this->assertSame(1, $e->movements()->where('type', 'SALDO_INICIAL')->count());
    }

    public function test_saldo_inicial_rechazado_si_ya_hay_ganado_antes_del_corte(): void
    {
        $this->legal();
        $e = $this->empleado();   // ingreso hace 2 años y 10 días
        $this->getJson("/api/employees/{$e->id}")->assertOk();   // acredita años 1 y 2
        $this->postJson("/api/employees/{$e->id}/movements", ['type' => 'SALDO_INICIAL', 'days' => 5,
            'movement_date' => $this->hoy()->format('Y-m-d'), 'reason' => 'corte tardio'])
            ->assertStatus(422)->assertJsonValidationErrors('movement_date');
    }

    public function test_ajuste_cero_y_motivo_obligatorio(): void
    {
        $this->legal();
        $e = $this->empleado();
        $this->postJson("/api/employees/{$e->id}/movements", ['type' => 'AJUSTE', 'days' => 0, 'reason' => 'ajuste en cero'])
            ->assertStatus(422)->assertJsonValidationErrors('days');
        $this->postJson("/api/employees/{$e->id}/movements", ['type' => 'AJUSTE', 'days' => -2])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/employees/{$e->id}/movements", ['type' => 'AJUSTE', 'days' => -2, 'reason' => 'abc'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/employees/{$e->id}/movements", ['type' => 'AJUSTE', 'days' => -2, 'reason' => 'corrige pago'])->assertStatus(201);
        $this->postJson("/api/employees/{$e->id}/movements", ['type' => 'GANADO', 'days' => 15, 'reason' => 'manual no permitido'])->assertStatus(422);
    }

    // ------------------------------------------------------------ 6. departamentos y feriados

    public function test_departamento_duplicado_422(): void
    {
        $this->legal();
        $n = 'DEPTO UNICO ' . $this->k;
        $this->postJson('/api/departments', ['name' => $n])->assertStatus(201);
        $this->postJson('/api/departments', ['name' => strtolower($n)])->assertStatus(422)->assertJsonValidationErrors('name');
        $id = Department::where('name', $n)->value('id');
        $this->patchJson("/api/departments/$id/deactivate")->assertOk();
        $this->assertFalse((bool) Department::find($id)->status);
    }

    public function test_feriados_un_activo_por_dia(): void
    {
        $this->legal();
        $f = '2031-05-24';
        $a = $this->postJson('/api/holidays', ['holiday_date' => $f, 'name' => 'batalla de pichincha', 'scope' => 'NACIONAL'])->assertStatus(201)->json('id');
        $this->postJson('/api/holidays', ['holiday_date' => $f, 'name' => 'otro feriado', 'scope' => 'LOCAL'])
            ->assertStatus(422)->assertJsonValidationErrors('holiday_date');
        $this->patchJson("/api/holidays/$a/deactivate")->assertOk();
        $b = $this->postJson('/api/holidays', ['holiday_date' => $f, 'name' => 'otro feriado', 'scope' => 'LOCAL'])->assertStatus(201)->json('id');
        $this->patchJson("/api/holidays/$a/reactivate")->assertStatus(422)->assertJsonValidationErrors('holiday_date');
        $this->assertFalse((bool) Holiday::find($a)->status);
        $this->assertTrue((bool) Holiday::find($b)->status);
    }

    // ------------------------------------------------------------ 7. permisos

    public function test_sin_sesion_401(): void
    {
        $this->getJson('/api/employees')->assertStatus(401);
        $this->getJson('/api/departments')->assertStatus(401);
        $this->getJson('/api/holidays')->assertStatus(401);
    }

    public function test_perfil_sin_pantalla_403_y_legal_con_pantalla_200(): void
    {
        Sanctum::actingAs($this->usuario('LEGAL', ['contratos_legal']));
        foreach (['/api/employees', '/api/employees/options', '/api/departments', '/api/holidays'] as $u) {
            $this->getJson($u)->assertStatus(403);
        }
        $this->postJson('/api/departments', ['name' => 'X' . $this->k])->assertStatus(403);

        Sanctum::actingAs($this->usuario('LEGAL', ['empleados_saldos']));
        foreach (['/api/employees', '/api/employees/options', '/api/departments', '/api/holidays'] as $u) {
            $this->getJson($u)->assertOk();
        }
    }

    public function test_otro_rol_sin_pantalla_403_y_admin_entra(): void
    {
        Sanctum::actingAs($this->usuario('COMERCIAL', ['pedido_semanal']));
        $this->getJson('/api/employees')->assertStatus(403);
        Sanctum::actingAs($this->usuario('ADMIN'));
        $this->getJson('/api/employees')->assertOk();
    }

    public function test_pantalla_existe_en_config_y_solo_para_legal(): void
    {
        $p = config('pantallas.empleados_saldos');
        $this->assertNotNull($p);
        $this->assertSame(['LEGAL'], $p['roles']);
    }

    public function test_migracion_agrega_empleados_saldos_a_perfiles_legal_existentes(): void
    {
        // DDL transaccional de PostgreSQL: se revierte con DatabaseTransactions
        $perfil = Profile::create(['name' => 'LEGAL MIG ' . $this->k, 'base_role' => 'LEGAL', 'screens' => ['contratos_legal'], 'status' => true]);
        $migracion = require database_path('migrations/2026_11_16_000001_vacaciones_fase1.php');
        $migracion->down();
        $this->assertFalse(Schema::hasTable('employees'));
        $this->assertNotContains('empleados_saldos', Profile::find($perfil->id)->screens);
        $this->assertContains('contratos_legal', Profile::find($perfil->id)->screens);
        $migracion->up();
        $this->assertTrue(Schema::hasTable('employees'));
        $this->assertContains('empleados_saldos', Profile::find($perfil->id)->screens);
        $this->assertContains('contratos_legal', Profile::find($perfil->id)->screens);
        // idempotente al repetir la parte de perfiles: no duplica la clave
        $this->assertSame(1, collect(Profile::find($perfil->id)->screens)->filter(fn ($s) => $s === 'empleados_saldos')->count());
    }

    // ------------------------------------------------------------ 8. bitácora

    public function test_bitacora_guarda_cedula_enmascarada(): void
    {
        $this->legal();
        $c = $this->cedula();
        $id = $this->postJson('/api/employees', $this->payload(['id_card' => $c]))->assertStatus(201)->json('id');
        $log = AuditLog::where('table_name', 'employees')->where('record_id', $id)->where('action', 'CREAR')->firstOrFail();
        $this->assertSame('****' . substr($c, -4), $log->changes['id_card']);
        $this->assertStringNotContainsString($c, json_encode($log->changes));

        // edición de la cédula: antes y después enmascarados
        $c2 = $this->cedula();
        $e = Employee::find($id);
        $e->update(['id_card' => $c2]);
        $log2 = AuditLog::where('table_name', 'employees')->where('record_id', $id)->where('action', 'EDITAR')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString($c2, json_encode($log2->changes));
        $this->assertStringNotContainsString($c, json_encode($log2->changes));
    }

    public function test_desactivar_queda_en_bitacora_sin_borrar(): void
    {
        $this->legal();
        $e = $this->empleado();
        $this->patchJson("/api/employees/{$e->id}/deactivate", ['termination_date' => $this->hoy()->format('Y-m-d')])->assertOk();
        $this->assertNotNull(Employee::find($e->id));
        $this->assertTrue(AuditLog::where('table_name', 'employees')->where('record_id', $e->id)->where('action', 'EDITAR')->exists());
    }

    // ------------------------------------------------------------ 9. comando

    public function test_comando_vacaciones_acreditar(): void
    {
        $e = $this->empleado(['hire_date' => $this->hoy()->subYears(2)->subDays(3)->format('Y-m-d')]);
        $this->assertSame(0, Artisan::call('vacaciones:acreditar'));
        $this->assertStringContainsString('ACREDITADOS:', Artisan::output());
        $this->assertSame(2, $e->movements()->where('type', 'GANADO')->count());
        Artisan::call('vacaciones:acreditar');
        $this->assertStringContainsString('ACREDITADOS: 0', Artisan::output());
        $this->assertSame(2, $e->movements()->where('type', 'GANADO')->count());
    }
}
