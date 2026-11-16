<?php

// 16/11/2026: pruebas de "Materiales del pedido contra stock de bodegas (preliminar)".
// Base: siae_test con DatabaseTransactions (nunca RefreshDatabase). Cada prueba arma sus datos.

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderLine;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementLine;
use App\Models\MaterialRecipe;
use App\Models\MovementReason;
use App\Models\ProducerQuota;
use App\Models\Profile;
use App\Models\Supply;
use App\Models\SupplyCategory;
use App\Models\ThirdParty;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MaterialesPedidoTest extends TestCase
{
    use DatabaseTransactions;

    private static int $n = 0;
    private string $k;
    private ?SupplyCategory $cat = null;
    private ?MovementReason $motivo = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('siae_test', \DB::connection()->getDatabaseName());
        $this->k = ++self::$n . strtoupper(substr(uniqid(), -5));
    }

    public function test_ruta_resuelve_el_controlador_sin_alias(): void
    {
        $this->assertFalse(class_exists('OrderMaterialsController', false), 'alias global presente: resultado no confiable');
        foreach (['api/customer-orders/materials', 'api/material-dispatch/order-materials'] as $uri) {
            $ruta = collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === $uri);
            $this->assertNotNull($ruta, $uri);
            $this->assertTrue(class_exists($ruta->getControllerClass()), "$uri apunta a una clase inexistente: " . $ruta->getControllerClass());
        }
    }

    private function usuario(string $rol, array $pantallas = ['pedido_semanal', 'despacho']): User
    {
        $perfil = $rol === 'ADMIN' ? null : Profile::create(['name' => "PERFIL $rol {$this->k}" . uniqid(), 'base_role' => $rol, 'screens' => $pantallas, 'status' => true]);

        return User::create(['name' => "USUARIO $rol", 'email' => 'u' . strtolower(uniqid()) . '@test.local', 'password' => 'Clave-Test-123',
            'role' => $rol, 'status' => true, 'must_change_password' => false, 'password_changed_at' => now(), 'profile_id' => $perfil?->id]);
    }

    private function marca(?string $code = null): Brand
    {
        $c = $code ?? "MMP{$this->k}" . uniqid();

        return Brand::create(['name' => "MARCA $c", 'code' => $c, 'status' => true]);
    }

    private function insumo(string $unidad = 'UNIDADES', float $costo = 0): Supply
    {
        $this->cat ??= SupplyCategory::create(['name' => "CAT MP {$this->k}", 'group_label' => 'CARTON', 'chargeable_to_producer' => true, 'code_prefix' => 'ZZ', 'status' => true]);

        return Supply::create(['supply_category_id' => $this->cat->id, 'code' => 'MP' . strtoupper(uniqid()), 'name' => 'INSUMO MP ' . uniqid(),
            'unit' => $unidad, 'cost' => $costo, 'status' => true]);
    }

    private function receta(Brand|string $marca, Supply $s, float $ratio): void
    {
        MaterialRecipe::create(['supply_id' => $s->id, 'base_cupo' => 'MARCA', 'ratio_per_box' => $ratio, 'unit' => $s->unit, 'status' => true,
            'brand' => $marca instanceof Brand ? $marca->code : $marca]);
    }

    private function bodega(bool $activa = true, ?User $resp = null): Warehouse
    {
        return Warehouse::create(['name' => 'BODEGA MP ' . uniqid(), 'code' => 'B' . strtoupper(substr(uniqid(), -6)), 'responsible_user_id' => $resp?->id, 'status' => $activa]);
    }

    private function movimiento(Warehouse $b, Supply $s, string $tipo, float $cant, ?int $cupoId = null): void
    {
        $this->motivo ??= MovementReason::create(['name' => "MOTIVO MP {$this->k}", 'type' => 'INGRESO', 'status' => true]);
        $m = InventoryMovement::create(['warehouse_id' => $b->id, 'movement_reason_id' => $this->motivo->id, 'type' => $tipo, 'date' => '2031-03-03',
            'status' => true, 'producer_quota_id' => $cupoId]);
        InventoryMovementLine::create(['inventory_movement_id' => $m->id, 'supply_id' => $s->id, 'quantity' => $cant, 'unit_cost' => 1, 'discount' => 0, 'total' => $cant]);
    }

    private function pedido(int $semana = 11, int $anio = 2031): CustomerOrder
    {
        return CustomerOrder::create(['week' => $semana, 'year' => $anio, 'expected_boxes' => 1000, 'status' => true]);
    }

    private function cupo(CustomerOrder $p): ProducerQuota
    {
        $prod = ThirdParty::create(['name' => 'PRODUCTOR MP ' . uniqid(), 'type' => 'PRODUCTOR', 'status' => true]);

        return ProducerQuota::create(['third_party_id' => $prod->id, 'week_number' => $p->week, 'year' => $p->year, 'total_cupo' => 100, 'status' => ProducerQuota::ASIGNADO, 'customer_order_id' => $p->id]);
    }

    private function estimar(User $u, array $lineas, ?int $pedidoId = null)
    {
        Sanctum::actingAs($u);

        return $this->postJson('/api/customer-orders/materials', ['customer_order_id' => $pedidoId, 'lines' => $lineas]);
    }

    private function linea(Brand $m, int $cajas, string $pres = 'CONVENCIONAL'): array
    {
        return ['brand_id' => $m->id, 'presentation' => $pres, 'boxes' => $cajas];
    }

    private function fila(array $json, Supply $s): ?array
    {
        return collect($json['insumos'])->firstWhere('supply_id', $s->id);
    }

    private function lineaPedido(CustomerOrder $p, Brand $m, int $cajas): void
    {
        $terCli = ThirdParty::create(['name' => 'CLIENTE MP ' . uniqid(), 'type' => 'CLIENTE', 'status' => true]);
        $cli = Customer::create(['customer_code' => 'CUST-MP' . uniqid(), 'third_party_id' => $terCli->id, 'country' => 'RUSIA', 'negotiation_type' => 'CONTRATO', 'status' => true]);
        CustomerOrderLine::create(['customer_order_id' => $p->id, 'customer_id' => $cli->id, 'brand_id' => $m->id, 'presentation' => 'CONVENCIONAL',
            'boxes' => $cajas, 'incoterm' => 'FOB', 'cargo_type' => 'CAJA_BAJO_CUBIERTA', 'negotiation_type' => 'CONTRATO']);
    }

    public function test_necesario_redondea_arriba_y_libras_con_dos_decimales(): void
    {
        $m = $this->marca();
        $u = $this->insumo('UNIDADES');
        $l = $this->insumo('LIBRAS');
        $this->receta($m, $u, 1.05);
        $this->receta($m, $l, 0.333);
        $admin = $this->usuario('ADMIN');
        $r = $this->estimar($admin, [$this->linea($m, 100)])->assertOk()->json();
        $this->assertEquals(105, $this->fila($r, $u)['necesario']);
        $this->assertEquals(33.3, $this->fila($r, $l)['necesario']);
        $r = $this->estimar($admin, [$this->linea($m, 101)])->json();
        $this->assertEquals(107, $this->fila($r, $u)['necesario']);   // 106.05 -> 107
        $this->assertEquals(33.63, $this->fila($r, $l)['necesario']); // 33.633 -> 33.63
    }

    public function test_descuenta_lo_ya_retirado_egreso_menos_devolucion(): void
    {
        $m = $this->marca();
        $s = $this->insumo();
        $this->receta($m, $s, 2);
        $p = $this->pedido();
        $cupo = $this->cupo($p);
        $otro = $this->cupo($this->pedido(12)); // cupo de OTRO pedido: no cuenta
        $b = $this->bodega();
        $this->movimiento($b, $s, 'EGRESO', 50, $cupo->id);
        $this->movimiento($b, $s, 'DEVOLUCION', 10, $cupo->id);
        $this->movimiento($b, $s, 'EGRESO', 999, $otro->id);
        $r = $this->estimar($this->usuario('ADMIN'), [$this->linea($m, 100)], $p->id)->assertOk()->json();
        $f = $this->fila($r, $s);
        $this->assertEquals(200, $f['formula']);
        $this->assertEquals(40, $f['ya_retirado']);
        $this->assertEquals(160, $f['necesario']);
    }

    public function test_stock_suma_bodegas_activas_e_ignora_inactivas(): void
    {
        $m = $this->marca();
        $s = $this->insumo();
        $this->receta($m, $s, 1);
        $a = $this->bodega();
        $b = $this->bodega();
        $x = $this->bodega(false);
        $this->movimiento($a, $s, 'INGRESO', 30);
        $this->movimiento($b, $s, 'INGRESO', 20);
        $this->movimiento($x, $s, 'INGRESO', 500);
        $r = $this->estimar($this->usuario('ADMIN'), [$this->linea($m, 100)])->json();
        $f = $this->fila($r, $s);
        $this->assertEquals(50, $f['stock']);
        $this->assertEquals(50, $f['faltante']);
        $this->assertNotContains($x->id, array_column($f['por_bodega'], 'warehouse_id'));
        $this->assertContains($a->id, array_column($f['por_bodega'], 'warehouse_id'));
    }

    public function test_faltante_y_cajas_alcanzan_con_la_menor_cobertura(): void
    {
        $m = $this->marca();
        $s1 = $this->insumo();
        $s2 = $this->insumo();
        $this->receta($m, $s1, 1);
        $this->receta($m, $s2, 2);
        $b = $this->bodega();
        $this->movimiento($b, $s1, 'INGRESO', 1000);
        $this->movimiento($b, $s2, 'INGRESO', 75); // 75/200 = 0.375
        $r = $this->estimar($this->usuario('ADMIN'), [$this->linea($m, 100)])->json();
        $this->assertEquals(125, $this->fila($r, $s2)['faltante']);
        $this->assertEquals(0, $this->fila($r, $s1)['faltante']);
        $this->assertSame(37, $r['cajas_alcanzan']); // floor(100 * 0.375)
        $this->assertSame(1, $r['con_faltante']);
    }

    public function test_packed_usa_receta_packed_si_existe_y_si_no_la_de_su_marca(): void
    {
        $m = $this->marca('PALMS_BANANA');
        $normal = $this->insumo();
        $packed = $this->insumo();
        $this->receta('PALMS_BANANA', $normal, 1);
        $admin = $this->usuario('ADMIN');
        $r = $this->estimar($admin, [$this->linea($m, 10, 'PACKED')])->json();
        $this->assertNotNull($this->fila($r, $normal));
        $this->receta('PALM_BANANAS_PACKED', $packed, 3);
        $r = $this->estimar($admin, [$this->linea($m, 10, 'PACKED')])->json();
        $this->assertEquals(30, $this->fila($r, $packed)['necesario']);
        $this->assertNull($this->fila($r, $normal));
        $r = $this->estimar($admin, [$this->linea($m, 10, 'CONVENCIONAL')])->json();
        $this->assertNotNull($this->fila($r, $normal));
        $this->assertNull($this->fila($r, $packed));
    }

    public function test_marca_sin_receta_va_a_sin_receta(): void
    {
        $m = $this->marca();
        $r = $this->estimar($this->usuario('ADMIN'), [$this->linea($m, 40)])->assertOk()->json();
        $this->assertSame([['marca' => $m->name, 'cajas' => 40]], $r['sin_receta']);
        $this->assertSame([], $r['insumos']);
        $this->assertNull($r['cajas_alcanzan']);
    }

    public function test_costo_lo_reciben_admin_gerencia_y_finanzas(): void
    {
        $m = $this->marca();
        $s = $this->insumo('UNIDADES', 0.5);
        $s0 = $this->insumo('UNIDADES', 0);
        $this->receta($m, $s, 2);
        $this->receta($m, $s0, 1);
        foreach (['ADMIN', 'GERENCIA', 'FINANZAS'] as $rol) {
            $r = $this->estimar($this->usuario($rol), [$this->linea($m, 100)])->assertOk()->json();
            $this->assertTrue($r['ve_costo'], $rol);
            $this->assertEquals(100, $r['costo'], $rol);
            $this->assertEquals(1, $r['costo_por_caja'], $rol);
            $this->assertSame(1, $r['insumos_sin_costo'], $rol);
            $this->assertEquals(0.5, $this->fila($r, $s)['costo_unitario'], $rol);
        }
    }

    public function test_exportaciones_no_recibe_ninguna_llave_de_costo(): void
    {
        $m = $this->marca();
        $s = $this->insumo('UNIDADES', 0.5);
        $this->receta($m, $s, 2);
        $res = $this->estimar($this->usuario('EXPORTACIONES'), [$this->linea($m, 100)])->assertOk();
        $r = $res->json();
        $this->assertFalse($r['ve_costo']);
        foreach (['costo', 'costo_por_caja', 'insumos_sin_costo'] as $llave) {
            $this->assertArrayNotHasKey($llave, $r);
        }
        $this->assertArrayNotHasKey('costo_unitario', $this->fila($r, $s));
        $this->assertArrayNotHasKey('costo', $this->fila($r, $s));
    }

    public function test_get_bodega_nunca_trae_dolares_ni_para_admin(): void
    {
        $m = $this->marca();
        $s = $this->insumo('UNIDADES', 0.5);
        $this->receta($m, $s, 2);
        $p = $this->pedido();
        $this->lineaPedido($p, $m, 100);
        Sanctum::actingAs($this->usuario('ADMIN'));
        $r = $this->getJson('/api/material-dispatch/order-materials?year=2031&week=11')->assertOk()->json();
        $this->assertSame($p->id, $r['pedido']['id']);
        $this->assertFalse($r['ve_costo']);
        foreach (['costo', 'costo_por_caja', 'insumos_sin_costo'] as $llave) {
            $this->assertArrayNotHasKey($llave, $r);
        }
        $this->assertArrayNotHasKey('costo_unitario', $this->fila($r, $s));
        $this->assertArrayNotHasKey('costo', $this->fila($r, $s));
    }

    public function test_bodeguero_ve_solo_su_bodega(): void
    {
        $m = $this->marca();
        $s = $this->insumo();
        $this->receta($m, $s, 1);
        $p = $this->pedido();
        $this->lineaPedido($p, $m, 100);
        $bodeguero = $this->usuario('BODEGUERO', ['despacho']);
        $mia = $this->bodega(true, $bodeguero);
        $otra = $this->bodega();
        $this->movimiento($mia, $s, 'INGRESO', 10);
        $this->movimiento($otra, $s, 'INGRESO', 30);
        Sanctum::actingAs($bodeguero);
        $r = $this->getJson('/api/material-dispatch/order-materials?year=2031&week=11')->assertOk()->json();
        $f = $this->fila($r, $s);
        $this->assertNull($f['stock']);
        $this->assertEquals(10, $f['stock_mi_bodega']);
        $this->assertSame([], $f['por_bodega']);
        $this->assertTrue($r['solo_mi_bodega']);
        $this->assertEquals(60, $f['faltante']); // el faltante usa todas las bodegas: 100 - 40
    }

    public function test_semana_sin_pedido_devuelve_pedido_null(): void
    {
        Sanctum::actingAs($this->usuario('ADMIN'));
        $this->getJson('/api/material-dispatch/order-materials?year=2099&week=50')->assertOk()->assertExactJson(['pedido' => null]);
    }

    public function test_permisos_y_validacion(): void
    {
        $m = $this->marca();
        $this->postJson('/api/customer-orders/materials', ['lines' => []])->assertStatus(401);
        $this->getJson('/api/material-dispatch/order-materials?year=2031&week=11')->assertStatus(401);

        $this->estimar($this->usuario('COMERCIAL', ['despacho']), [$this->linea($m, 1)])->assertStatus(403);
        Sanctum::actingAs($this->usuario('EXPORTACIONES', ['pedido_semanal']));
        $this->getJson('/api/material-dispatch/order-materials?year=2031&week=11')->assertStatus(403);

        $r = $this->estimar($this->usuario('ADMIN'), [['brand_id' => $m->id, 'presentation' => 'XXX', 'boxes' => 1]])->assertStatus(422);
        $msg = $r->json('errors')['lines.0.presentation'][0] ?? $r->json('message');
        $this->assertSame(mb_strtoupper($msg), $msg);
        $this->assertStringContainsString('PRESENTACIÓN', $msg);
    }
}
