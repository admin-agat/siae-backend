<?php

// 17/11/2026: auditoría de seguridad ronda 2. Base: siae_test con DatabaseTransactions.
// Las pruebas marcadas "FALLA A PROPÓSITO" DEMUESTRAN un hallazgo: fallan mientras el hueco exista
// y pasarán cuando se corrija en la aplicación. Las demás confirman que algo ya está bien.

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\MovementReason;
use App\Models\Profile;
use App\Models\ThirdParty;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SeguridadRonda2Test extends TestCase
{
    use DatabaseTransactions;

    private static int $n = 0;
    private string $k;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('siae_test', DB::connection()->getDatabaseName());
        $this->k = ++self::$n . strtolower(substr(uniqid(), -6));
    }

    private function perfil(string $base, array $pantallas): Profile
    {
        return Profile::create(['name' => "P{$this->k}" . count($pantallas) . $base . uniqid(), 'base_role' => $base, 'description' => 'TEST', 'screens' => $pantallas, 'status' => true]);
    }

    private function usuario(string $pref, string $rol, array $pantallas = [], ?string $depto = null): User
    {
        $perfil = $rol === 'ADMIN' ? null : $this->perfil($rol, $pantallas);
        return User::create(['name' => "U $pref{$this->k}", 'email' => "$pref{$this->k}@test.local", 'password' => 'Clave-Test-123', 'role' => $rol,
            'profile_id' => $perfil?->id, 'department' => $depto, 'status' => true, 'must_change_password' => false, 'password_changed_at' => now()]);
    }

    private function ocDepartamento(User $u): int
    {
        $prov = ThirdParty::create(['name' => "PROV {$this->k}" . $u->id, 'type' => 'PROVEEDOR', 'status' => true]);
        Sanctum::actingAs($u);
        return $this->postJson('/api/department-orders', [
            'third_party_id' => $prov->id, 'date' => '2026-11-10', 'reference' => 'CONCEPTO PRUEBA',
            'lines' => [['description' => 'ITEM', 'quantity_ordered' => 1, 'unit_price' => 10, 'tax_rate' => 15]],
        ])->assertCreated()->json('id');
    }

    // ───────── Ya está bien ─────────

    public function test_token_de_usuario_desactivado_deja_de_servir(): void
    {
        $u = $this->usuario('tok', 'COMERCIAL', ['compra_fruta']);
        $token = $u->createToken('x')->plainTextToken;
        $this->withToken($token)->getJson('/api/me')->assertOk();
        $u->update(['status' => false]); // desactivado "por fuera" de la pantalla Usuarios
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_departamento_no_ve_oc_de_otro_departamento(): void
    {
        $a = $this->usuario('da', 'DEPARTAMENTO', ['oc_departamento'], "DEPA{$this->k}");
        $b = $this->usuario('db', 'DEPARTAMENTO', ['oc_departamento'], "DEPB{$this->k}");
        $idA = $this->ocDepartamento($a);
        Sanctum::actingAs($b);
        $this->getJson("/api/department-orders/$idA")->assertNotFound();
        $this->patchJson("/api/department-orders/$idA/cancel", ['cancellation_reason' => 'PRUEBA'])->assertNotFound();
    }

    public function test_login_no_revela_si_el_usuario_existe(): void
    {
        $u = $this->usuario('lg', 'COMERCIAL', ['compra_fruta']);
        $r1 = $this->postJson('/api/login', ['email' => $u->email, 'password' => 'mala-clave-1'])->assertStatus(422);
        $r2 = $this->postJson('/api/login', ['email' => "noexiste{$this->k}@test.local", 'password' => 'mala-clave-1'])->assertStatus(422);
        $this->assertSame($r1->json('errors.email'), $r2->json('errors.email'));
    }

    public function test_no_admin_no_puede_gestionar_usuarios_ni_perfiles(): void
    {
        $u = $this->usuario('na', 'GERENCIA', ['dashboard']);
        Sanctum::actingAs($u);
        $this->putJson("/api/users/{$u->id}", ['name' => 'X', 'email' => 'x@x.com', 'profile_id' => 1])->assertForbidden();
        $this->postJson('/api/users', [])->assertForbidden();
        $this->postJson('/api/profiles', [])->assertForbidden();
        $this->getJson('/api/audit-logs')->assertForbidden();
        $this->assertSame('GERENCIA', $u->fresh()->role);
    }

    public function test_cambio_de_clave_cierra_las_otras_sesiones(): void
    {
        $u = $this->usuario('cc', 'COMERCIAL', ['compra_fruta']);
        $otro = $u->createToken('celular')->plainTextToken;
        $actual = $u->createToken('pc')->plainTextToken;
        $this->withToken($actual)->putJson('/api/me/password', ['actual' => 'Clave-Test-123', 'nueva' => 'Nueva-Clave-456', 'nueva_confirmation' => 'Nueva-Clave-456'])->assertOk();
        app('auth')->forgetGuards();
        $this->withToken($otro)->getJson('/api/me')->assertUnauthorized();
    }

    // ───────── FALLA A PROPÓSITO (demuestran hallazgos) ─────────

    // FALLA A PROPÓSITO (MEDIO): GET /api/purchase-orders/{id} no excluye las OC de departamento (warehouse_id NULL).
    // Cualquier usuario con una sola pantalla de inventario (aquí solo "stock") lee las OC de todos los departamentos
    // (proveedor, líneas y precios) recorriendo ids. Debería responder 404/403.
    public function test_falla_a_proposito_oc_de_departamento_legible_por_pantalla_de_inventario(): void
    {
        $dep = $this->usuario('dd', 'DEPARTAMENTO', ['oc_departamento'], "DEPX{$this->k}");
        $id = $this->ocDepartamento($dep);
        $lector = $this->usuario('lec', 'JEFE_BODEGA', ['stock']);
        Sanctum::actingAs($lector);
        $this->getJson("/api/purchase-orders/$id")->assertStatus(404);
    }

    // FALLA A PROPÓSITO (MEDIO): los 403 (intentos de entrar a pantallas sin permiso) no dejan rastro en audit_logs.
    // Solo se registran LOGIN_FALLIDO. Con una cuenta robada se puede tantear la API sin que nadie lo vea.
    public function test_falla_a_proposito_403_no_queda_en_bitacora(): void
    {
        $u = $this->usuario('b403', 'GERENCIA', ['dashboard']);
        Sanctum::actingAs($u);
        $antes = AuditLog::count();
        $this->getJson('/api/audit-logs')->assertForbidden();
        $this->assertGreaterThan($antes, AuditLog::count(), 'El 403 no quedó registrado en la bitácora.');
    }

    // FALLA A PROPÓSITO (MEDIO): DELETE /inventory-movements/{id} (y PUT, y anular despachos) solo valida el ROL, no que
    // la bodega del movimiento sea una de las asignadas al usuario. Un COORDINADOR limitado a la bodega A anula
    // movimientos de la bodega B (cambia el stock y el costo promedio). Debería ser 403.
    public function test_falla_a_proposito_coordinador_con_bodega_asignada_anula_movimiento_de_otra_bodega(): void
    {
        $coord = $this->usuario('co', 'COORDINADOR_INVENTARIO', ['movimientos']);
        $bodA = Warehouse::create(['name' => "BOD A{$this->k}", 'code' => "A{$this->k}", 'status' => true]);
        $bodB = Warehouse::create(['name' => "BOD B{$this->k}", 'code' => "B{$this->k}", 'status' => true]);
        $coord->warehouses()->sync([$bodA->id]);
        $motivo = MovementReason::create(['name' => "MOTIVO {$this->k}", 'type' => 'INGRESO', 'status' => true]);
        $mov = InventoryMovement::create(['warehouse_id' => $bodB->id, 'movement_reason_id' => $motivo->id, 'type' => 'INGRESO',
            'date' => '2026-11-10', 'created_by_user_id' => $coord->id, 'status' => true]);
        Sanctum::actingAs($coord);
        $this->deleteJson("/api/inventory-movements/{$mov->id}")->assertForbidden();
    }
}
