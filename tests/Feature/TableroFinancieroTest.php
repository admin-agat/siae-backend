<?php

// 16/11/2026: pruebas del Tablero financiero (GET /api/boards/finance).
// Base: siae_test con DatabaseTransactions (nunca RefreshDatabase). Cada prueba arma sus datos.

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderLine;
use App\Models\ProducerQuota;
use App\Models\ProducerQuotaLine;
use App\Models\Profile;
use App\Models\PurchaseBlock;
use App\Models\Shipment;
use App\Models\ShipmentCost;
use App\Models\ShipmentExpense;
use App\Models\ShipmentLine;
use App\Models\ShippingLine;
use App\Models\ThirdParty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TableroFinancieroTest extends TestCase
{
    use DatabaseTransactions;

    private static int $n = 0;
    private int $anio = 2031; // año aislado para no mezclar con datos ajenos
    private string $k;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('siae_test', \DB::connection()->getDatabaseName());
        $this->k = ++self::$n . '-' . strtoupper(substr(uniqid(), -6));
    }

    private function usuario(string $rol, ?Profile $perfil = null): User
    {
        return User::create(['name' => "USUARIO $rol", 'email' => 'u' . strtolower(uniqid()) . '@test.local', 'password' => 'Clave-Test-123',
            'role' => $rol, 'status' => true, 'must_change_password' => false, 'password_changed_at' => now(), 'profile_id' => $perfil?->id]);
    }

    private function perfil(string $base, array $pantallas): Profile
    {
        return Profile::create(['name' => "PERFIL $base {$this->k}" . uniqid(), 'base_role' => $base, 'screens' => $pantallas, 'status' => true]);
    }

    private function url(array $q = []): string
    {
        return '/api/boards/finance?' . http_build_query($q + ['year' => $this->anio, 'week_from' => 10, 'week_to' => 12]);
    }

    /** Pedido + barco + línea con precio + compra con precio real + gasto del viaje. */
    private function armar(bool $completo = true, int $semana = 11): array
    {
        $k = $this->k . uniqid();
        $pedido = CustomerOrder::create(['week' => $semana, 'year' => $this->anio, 'expected_boxes' => 1000, 'status' => true]);
        $terCli = ThirdParty::create(['name' => "CLIENTE TF $k", 'type' => 'CLIENTE', 'status' => true]);
        $cliente = Customer::create(['customer_code' => "CUST-TF$k", 'third_party_id' => $terCli->id, 'country' => 'RUSIA', 'negotiation_type' => 'CONTRATO', 'status' => true]);
        $marca = Brand::create(['name' => "MARCA TF $k", 'code' => "MTF$k", 'status' => true]);
        $lineaPed = CustomerOrderLine::create(['customer_order_id' => $pedido->id, 'customer_id' => $cliente->id, 'brand_id' => $marca->id,
            'presentation' => 'CONVENCIONAL', 'boxes' => 1000, 'incoterm' => 'FOB', 'cargo_type' => 'CAJA_BAJO_CUBIERTA',
            'negotiation_type' => 'CONTRATO', 'box_price' => $completo ? 10.00 : null]);
        $naviera = ShippingLine::create(['name' => "NAVIERA TF $k"]);
        $barco = Shipment::create(['code' => "STF$k", 'registration_date' => '2031-03-03', 'departure_date' => '2031-03-12', 'vessel_name' => "NAVE TF $k",
            'shipping_line_id' => $naviera->id, 'week_start' => $semana, 'week_end' => $semana, 'year' => $this->anio,
            'customer_order_id' => $pedido->id, 'status' => true]);
        ShipmentLine::create(['shipment_id' => $barco->id, 'customer_order_line_id' => $lineaPed->id, 'customer_id' => $cliente->id,
            'week' => $semana, 'type' => 'CAJA', 'box_quantity' => 1000, 'container_quantity' => 0, 'status' => true]);
        if ($completo) {
            ShipmentCost::create(['shipment_id' => $barco->id, 'under_deck_amount' => 100]);
            ShipmentExpense::create(['shipment_id' => $barco->id, 'customer_id' => null, 'category' => 'SEGURO', 'amount' => 50]);
        }
        $prod = ThirdParty::create(['name' => "PRODUCTOR TF $k", 'type' => 'PRODUCTOR', 'status' => true]);
        $bloque = PurchaseBlock::create(['customer_order_id' => $pedido->id, 'shipment_id' => $barco->id, 'brand_id' => $marca->id,
            'presentation' => 'CONVENCIONAL', 'customer_id' => $cliente->id, 'cargo_mode' => 'CAJA_BAJO_CUBIERTA', 'target_boxes' => 1000]);
        $cupo = ProducerQuota::create(['third_party_id' => $prod->id, 'week_number' => $semana, 'year' => $this->anio, 'total_cupo' => 1000, 'status' => ProducerQuota::ASIGNADO, 'customer_order_id' => $pedido->id]);
        ProducerQuotaLine::create(['producer_quota_id' => $cupo->id, 'variety' => $marca->code, 'quantity_cajas' => 1000, 'customer_id' => $cliente->id,
            'brand_id' => $marca->id, 'official_price' => 7.5, 'real_price' => $completo ? 6.0 : null, 'negotiation_type' => 'CONTRATO',
            'payment_scheme' => '100', 'purchase_block_id' => $bloque->id]);

        return [$pedido, $barco];
    }

    // ───────────── Permisos ─────────────

    public function test_sin_sesion_da_401(): void
    {
        $this->getJson($this->url())->assertStatus(401);
    }

    public function test_admin_da_200(): void
    {
        Sanctum::actingAs($this->usuario('ADMIN'));
        $this->getJson($this->url())->assertOk();
    }

    public function test_perfil_con_la_pantalla_da_200(): void
    {
        $p = $this->perfil('FINANZAS', ['tablero_financiero']);
        Sanctum::actingAs($this->usuario("FINANZAS", $p));
        $this->getJson($this->url())->assertOk(); // FALLA: la ruta está dentro del grupo $lectura de routes/api.php (línea 288) que no incluye tablero_financiero
    }

    public function test_perfil_sin_la_pantalla_da_403(): void
    {
        $p = $this->perfil('EXPORTACIONES', ['dashboard']);
        Sanctum::actingAs($this->usuario('EXPORTACIONES', $p));
        $this->getJson($this->url())->assertStatus(403);
    }

    public function test_excepcion_por_persona_agregada_da_200(): void
    {
        $p = $this->perfil('EXPORTACIONES', ['dashboard']);
        $u = $this->usuario('EXPORTACIONES', $p);
        $u->update(['screens_added' => ['tablero_financiero']]);
        Sanctum::actingAs($u);
        $this->getJson($this->url())->assertOk();
    }

    public function test_excepcion_por_persona_quitada_da_403(): void
    {
        $p = $this->perfil('FINANZAS', ['tablero_financiero']);
        $u = $this->usuario('FINANZAS', $p);
        $u->update(['screens_removed' => ['tablero_financiero']]);
        Sanctum::actingAs($u);
        $this->getJson($this->url())->assertStatus(403);
    }

    // ───────────── Validación ─────────────

    public function test_desde_mayor_que_hasta_da_422_en_mayusculas(): void
    {
        Sanctum::actingAs($this->usuario('ADMIN'));
        $r = $this->getJson($this->url(['week_from' => 20, 'week_to' => 5]))->assertStatus(422);
        $m = collect($r->json('errors'))->flatten();
        $this->assertNotEmpty($m);
        foreach ($m as $x) {
            $this->assertSame(mb_strtoupper($x), $x);
        }
    }

    public function test_semana_fuera_de_rango_da_422_en_mayusculas(): void
    {
        Sanctum::actingAs($this->usuario('ADMIN'));
        $r = $this->getJson($this->url(['week_to' => 60]))->assertStatus(422);
        foreach (collect($r->json('errors'))->flatten() as $x) {
            $this->assertSame(mb_strtoupper($x), $x);
        }
    }

    // ───────────── Cálculo ─────────────

    public function test_totales_coinciden_con_voyage_profit_del_pedido(): void
    {
        Sanctum::actingAs($this->usuario('ADMIN'));
        [$pedido] = $this->armar(true);

        $vp = $this->getJson("/api/voyage-profit?customer_order_id={$pedido->id}")->assertOk()->json('total');
        $tf = $this->getJson($this->url())->assertOk()->json();

        $this->assertEquals(10000.00, $vp['venta']);
        $this->assertEquals($vp['venta'], $tf['totales']['venta']);
        $this->assertEquals($vp['ganancia'], $tf['totales']['ganancia']);
        $this->assertEquals(1000, $tf['totales']['cajas']);
        $this->assertSame(1, $tf['totales']['barcos']);
        $this->assertSame(0, $tf['totales']['barcos_incompletos']);
        $this->assertEquals(round($vp['ganancia'] / $vp['venta'] * 100, 2), $tf['totales']['margen']);
        $s11 = collect($tf['semanas'])->firstWhere('week', 11);
        $this->assertEquals($vp['venta'], $s11['venta']);
        $this->assertCount(1, $tf['por_cliente']);
        $this->assertCount(1, $tf['barcos']);
    }

    public function test_barco_incompleto_marca_avisos(): void
    {
        Sanctum::actingAs($this->usuario('ADMIN'));
        $this->armar(false);
        $tf = $this->getJson($this->url())->assertOk()->json();
        $this->assertSame(1, $tf['totales']['barcos_incompletos']);
        $this->assertTrue($tf['barcos'][0]['incompleto']);
        $this->assertNotEmpty($tf['barcos'][0]['avisos']);
    }

    public function test_rango_sin_barcos_da_ceros_y_margen_null(): void
    {
        Sanctum::actingAs($this->usuario('ADMIN'));
        $tf = $this->getJson($this->url(['week_from' => 30, 'week_to' => 31]))->assertOk()->json();
        $this->assertSame(0, $tf['totales']['barcos']);
        $this->assertEquals(0, $tf['totales']['venta']);
        $this->assertEquals(0, $tf['totales']['ganancia']);
        $this->assertNull($tf['totales']['margen']);
        $this->assertCount(2, $tf['semanas']);
        $this->assertSame([], $tf['por_cliente']);
        $this->assertSame([], $tf['barcos']);
    }

    public function test_no_envia_ruc_ni_cuentas(): void
    {
        Sanctum::actingAs($this->usuario('ADMIN'));
        $this->armar(true);
        $txt = strtolower($this->getJson($this->url())->assertOk()->getContent());
        foreach (['"ruc', 'tax_id', 'bank', 'account_number', 'cuenta'] as $x) {
            $this->assertStringNotContainsString($x, $txt);
        }
    }

    public function test_consultas_y_tiempo_con_varios_barcos(): void
    {
        Sanctum::actingAs($this->usuario('ADMIN'));
        for ($i = 0; $i < 8; $i++) {
            $this->armar(true, 5 + $i);
        }
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $t = microtime(true);
        $this->getJson($this->url(['week_from' => 5, 'week_to' => 12]))->assertOk();
        $ms = (microtime(true) - $t) * 1000;
        $q = count(\DB::getQueryLog());
        fwrite(STDERR, sprintf("\n[MEDICION] barcos=8 consultas=%d tiempo=%.0f ms\n", $q, $ms));
        $this->assertLessThan(2000, $ms);
    }
}
