<?php

// 17/11/2026: pruebas del tipo base TARJADOR (persona del puerto que llena tarjas): solo ve Tarjas, sin precios ni montos.
// Base: siae_test con DatabaseTransactions (nunca RefreshDatabase). Cada prueba arma sus datos.

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderLine;
use App\Models\Farm;
use App\Models\Port;
use App\Models\Profile;
use App\Models\ProducerQuotaLine;
use App\Models\PurchaseBlock;
use App\Models\Shipment;
use App\Models\ShippingLine;
use App\Models\ThirdParty;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TarjadorTest extends TestCase
{
    use DatabaseTransactions;

    private static int $n = 0;

    private User $admin;
    private User $tarjador;
    private CustomerOrder $pedido;
    private Shipment $barco;
    private ProducerQuotaLine $compra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('siae_test', DB::connection()->getDatabaseName());
        $k = ++self::$n . '-' . strtoupper(substr(uniqid(), -6));
        $this->admin = $this->usuario("adm$k", 'ADMIN');
        $perfil = Profile::firstOrCreate(['name' => 'TARJADOR'], ['base_role' => 'TARJADOR', 'description' => 'TEST', 'screens' => ['tarjas'], 'status' => true]);
        $this->tarjador = $this->usuario("tar$k", 'TARJADOR', $perfil->id);

        $bodeguero = $this->usuario("bod$k", 'BODEGUERO');
        $bodega = Warehouse::create(['name' => "BODEGA T$k", 'code' => "BT$k", 'responsible_user_id' => $bodeguero->id, 'status' => true]);
        $puerto = Port::create(['name' => "PUERTO T$k", 'port_type' => 'SALIDA', 'status' => true]);
        $prod = ThirdParty::create(['name' => "PRODUCTOR T$k", 'type' => 'PRODUCTOR', 'status' => true, 'retention_regime' => 'NORMAL']);
        $finca = Farm::create(['third_party_id' => $prod->id, 'name' => "FINCA T$k", 'magap_code' => 'C-0001', 'status' => true]);
        $ct = ThirdParty::create(['name' => "CLIENTE T$k", 'type' => 'CLIENTE', 'status' => true]);
        $cliente = Customer::create(['customer_code' => "CUST-T$k", 'third_party_id' => $ct->id, 'country' => 'RUSIA', 'negotiation_type' => 'CONTRATO', 'status' => true]);
        $marca = Brand::create(['name' => "MARCA T$k", 'code' => "MT$k", 'status' => true]);
        $cliente->brands()->attach($marca->id);
        $this->pedido = CustomerOrder::create(['week' => 46, 'year' => 2026, 'expected_boxes' => 20000, 'status' => true]);
        CustomerOrderLine::create(['customer_order_id' => $this->pedido->id, 'customer_id' => $cliente->id, 'brand_id' => $marca->id,
            'presentation' => 'CONVENCIONAL', 'boxes' => 20000, 'incoterm' => 'FOB', 'cargo_type' => 'CAJA_BAJO_CUBIERTA', 'negotiation_type' => 'CONTRATO']);
        $linea = ShippingLine::create(['name' => "NAVIERA T$k"]);
        $this->barco = Shipment::create(['code' => "ST$k", 'registration_date' => '2026-11-09', 'departure_date' => '2026-11-20', 'vessel_name' => "NAVE T$k",
            'shipping_line_id' => $linea->id, 'week_start' => 46, 'week_end' => 46, 'year' => 2026, 'customer_order_id' => $this->pedido->id, 'status' => true]);

        // la compra la hace Comercial (aquí el ADMIN); luego entra el tarjador
        Sanctum::actingAs($this->admin);
        $url = "/api/customer-orders/{$this->pedido->id}/purchases";
        $version = $this->getJson($url)->assertOk()->json('version');
        $bloque = PurchaseBlock::where('customer_order_id', $this->pedido->id)->where('brand_id', $marca->id)->value('id');
        $this->putJson($url, ['version' => $version, 'blocks' => [[
            'id' => $bloque, 'shipment_id' => $this->barco->id, 'brand_id' => $marca->id, 'presentation' => 'CONVENCIONAL',
            'customer_id' => $cliente->id, 'cargo_mode' => 'CAJA_BAJO_CUBIERTA', 'target_boxes' => 20000, 'purchases' => [[
                'third_party_id' => $prod->id, 'farm_id' => $finca->id, 'boxes' => 1000, 'official_price' => 7.0, 'real_price' => 6.0,
                'negotiation_type' => 'SPOT', 'payment_scheme' => '100', 'pickup_warehouse_id' => $bodega->id, 'delivery_port_id' => $puerto->id]],
        ]]])->assertOk();
        $bloque = PurchaseBlock::where('customer_order_id', $this->pedido->id)->where('brand_id', $marca->id)->value('id');
        $this->compra = ProducerQuotaLine::where('purchase_block_id', $bloque)->firstOrFail();
        Sanctum::actingAs($this->tarjador);
    }

    private function usuario(string $k, string $rol, ?int $perfil = null): User
    {
        return User::create(['name' => "USUARIO $k", 'email' => "$k@test.local", 'password' => 'Clave-Test-123', 'role' => $rol,
            'profile_id' => $perfil, 'status' => true, 'must_change_password' => false, 'password_changed_at' => now()]);
    }

    /** Recorre el JSON y devuelve las claves con dinero (price, amount, precio, monto, total...). */
    private function clavesDeDinero($datos, string $ruta = ''): array
    {
        $malas = [];
        if (is_array($datos)) {
            foreach ($datos as $k => $v) {
                if (is_string($k) && !str_contains($k, 'boxes') && preg_match('/price|amount|precio|monto|total|cost|costo|valor|retention|retencion/i', $k)) {
                    $malas[] = "$ruta.$k";
                }
                $malas = array_merge($malas, $this->clavesDeDinero($v, "$ruta.$k"));
            }
        }
        return $malas;
    }

    private function cuerpoTarja(int $embarcadas = 100): array
    {
        return ['tallies' => [[
            'producer_quota_line_id' => $this->compra->id, 'shipment_id' => $this->barco->id, 'date' => '2026-11-12',
            'boxes_shipped' => $embarcadas, 'boxes_fallen' => 1, 'boxes_rejected' => 2, 'boxes_missing' => 3,
        ]]];
    }

    // ───────────────────────── Acceso permitido ─────────────────────────

    public function test_tarjador_usa_semanas_tarjas_y_las_guarda(): void
    {
        $this->getJson('/api/tallies/weeks')->assertOk();
        $this->getJson("/api/customer-orders/{$this->pedido->id}/tallies")->assertOk()->assertJsonPath('purchases.0.boxes', 1000);
        $this->putJson("/api/customer-orders/{$this->pedido->id}/tallies", $this->cuerpoTarja())->assertOk()->assertJsonPath('tallies.0.boxes_received', 94);
    }

    public function test_tarjador_ve_la_orden_de_corte(): void
    {
        $r = $this->getJson("/api/customer-orders/{$this->pedido->id}/tallies")->assertOk();
        $c = $r->json('purchases.0');
        $this->assertNotEmpty($c['third_party']['name']);
        $this->assertNotEmpty($c['brand']['name']);
        $this->assertArrayHasKey('farm_name', $c);
        $this->assertSame(1000, $c['boxes']);
    }

    // ───────────────────────── 403 en lectura general y dinero ─────────────────────────

    public function test_tarjador_recibe_403_en_lectura_general_y_dinero(): void
    {
        $id = $this->pedido->id;
        foreach (['/api/customer-orders', "/api/customer-orders/$id", '/api/port-tariff-items', '/api/customer-contracts', '/api/ports',
                     '/api/settlements', '/api/settlements/quotas', '/api/producer-advances', "/api/customer-orders/$id/purchases",
                     '/api/producer-contracts/activos', '/api/users', '/api/profiles'] as $url) {
            $this->assertSame(403, $this->getJson($url)->status(), "esperaba 403 en $url");
        }
    }

    public function test_tarjador_no_puede_guardar_compras_ni_liquidar(): void
    {
        $id = $this->pedido->id;
        $this->putJson("/api/customer-orders/$id/purchases", ['version' => 1, 'blocks' => []])->assertStatus(403);
        $this->postJson('/api/settlements', [])->assertStatus(403);
    }

    /** Hallazgo si falla: precios oficiales MAG quedan abiertos a cualquier usuario autenticado. */
    public function test_precios_oficiales_mag_no_deben_leerse_con_tarjas(): void
    {
        $this->getJson('/api/official-prices')->assertStatus(403);
    }

    // ───────────────────────── Sin dinero en las respuestas ─────────────────────────

    public function test_respuestas_de_tarjas_no_traen_dinero(): void
    {
        $semanas = $this->getJson('/api/tallies/weeks')->assertOk()->json();
        $this->assertNotEmpty($semanas);
        $this->assertSame([], $this->clavesDeDinero($semanas, 'weeks'));

        $show = $this->getJson("/api/customer-orders/{$this->pedido->id}/tallies")->assertOk()->json();
        $put = $this->putJson("/api/customer-orders/{$this->pedido->id}/tallies", $this->cuerpoTarja())->assertOk()->json();
        $this->assertSame([], $this->clavesDeDinero($show, 'show'));
        $this->assertSame([], $this->clavesDeDinero($put, 'put'));
    }

    public function test_tallies_weeks_cuenta_cajas_pedidas_y_compradas(): void
    {
        $s = collect($this->getJson('/api/tallies/weeks')->json())->firstWhere('id', $this->pedido->id);
        $this->assertSame(20000, (int) $s['total_boxes']);
        $this->assertSame(1000, (int) $s['purchased_boxes']);
    }

    // ───────────────────────── Perfiles y excepciones ─────────────────────────

    public function test_perfil_tarjador_con_otra_pantalla_se_rechaza(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/profiles', ['name' => 'TARJADOR PRUEBA', 'base_role' => 'TARJADOR', 'description' => 'X', 'screens' => ['tarjas', 'liquidar']])
            ->assertStatus(422)->assertJsonValidationErrors('screens');
        $this->postJson('/api/profiles', ['name' => 'TARJADOR OK', 'base_role' => 'TARJADOR', 'description' => 'X', 'screens' => ['tarjas']])->assertCreated();
        $this->assertSame(0, Profile::where('name', 'TARJADOR PRUEBA')->count());
    }

    public function test_excepcion_por_persona_a_tarjador_se_rechaza(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/users/{$this->tarjador->id}", ['name' => 'TARJADOR X', 'email' => $this->tarjador->email, 'profile_id' => $this->tarjador->profile_id,
            'screens_added' => ['liquidar']])->assertStatus(422)->assertJsonValidationErrors('screens_added');
        $this->postJson('/api/users', ['name' => 'NUEVO TARJADOR', 'email' => 'nuevo-tar@test.local', 'password' => 'Clave-Test-123',
            'profile_id' => $this->tarjador->profile_id, 'screens_added' => ['liquidar']])->assertStatus(422)->assertJsonValidationErrors('screens_added');
    }

    public function test_pantallas_del_tarjador_solo_tarjas_aun_con_datos_sucios(): void
    {
        $this->assertSame(['tarjas'], $this->tarjador->pantallas());
        // datos que se colaron por fuera de la pantalla (SQL, seeders): el modelo igual los veda
        $this->tarjador->forceFill(['screens_added' => ['liquidar', 'pagos', 'tablero_financiero']])->save();
        $this->assertSame(['tarjas'], $this->tarjador->fresh()->pantallas());
        $this->tarjador->profile->forceFill(['screens' => ['tarjas', 'liquidar', 'dashboard']])->save();
        $this->assertSame(['tarjas'], $this->tarjador->fresh()->pantallas());
        // y el backend le niega esas rutas
        Sanctum::actingAs($this->tarjador->fresh());
        $this->getJson('/api/settlements')->assertStatus(403);
        $this->getJson('/api/customer-orders')->assertStatus(403);
    }

    public function test_me_del_tarjador_devuelve_solo_tarjas(): void
    {
        $r = $this->getJson('/api/me')->assertOk();
        $this->assertSame(['tarjas'], $r->json('screens') ?? $r->json('pantallas'));
        $this->assertSame([], $this->clavesDeDinero($r->json(), 'me'));
    }

    // ───────────────────────── LIQUIDACIONES intacto ─────────────────────────

    public function test_liquidaciones_sigue_usando_tarjas_y_lo_suyo(): void
    {
        $perfil = Profile::firstOrCreate(['name' => 'LIQUIDACIONES'], ['base_role' => 'LIQUIDACIONES', 'description' => 'T', 'status' => true,
            'screens' => ['tarjas', 'anticipos', 'liquidar', 'pagos']]);
        $perfil->forceFill(['screens' => ['tarjas', 'anticipos', 'liquidar', 'pagos'], 'status' => true])->save();
        $liq = $this->usuario('liq' . uniqid(), 'LIQUIDACIONES', $perfil->id);
        Sanctum::actingAs($liq);
        $this->getJson('/api/tallies/weeks')->assertOk();
        $this->getJson("/api/customer-orders/{$this->pedido->id}/tallies")->assertOk();
        $this->putJson("/api/customer-orders/{$this->pedido->id}/tallies", $this->cuerpoTarja())->assertOk();
        $this->getJson('/api/settlements')->assertOk();
        $this->getJson('/api/producer-advances')->assertOk();
        // sigue sin lo que no es suyo
        $this->getJson("/api/customer-orders/{$this->pedido->id}/purchases")->assertStatus(403);
    }

    // ───────────────────────── Migración ─────────────────────────

    public function test_migracion_tarjador_hace_down_y_up(): void
    {
        $m = require base_path('database/migrations/2026_11_17_000002_tipo_tarjador.php');
        // dentro de la transacción de la prueba (Postgres admite DDL transaccional): se deshace sola al terminar
        DB::table('users')->where('role', 'TARJADOR')->update(['role' => 'ADMIN']);
        DB::table('profiles')->where('base_role', 'TARJADOR')->update(['base_role' => 'LEGAL']);
        $m->down();
        $this->assertSame(0, DB::table('profiles')->where('base_role', 'TARJADOR')->count());
        $this->assertFalse($this->aceptaRol('TARJADOR'));
        $m->up();
        $this->assertTrue($this->aceptaRol('TARJADOR'));
        $this->assertSame(1, DB::table('profiles')->where('name', 'TARJADOR')->count());
        $m->up(); // repetible
        $this->assertSame(1, DB::table('profiles')->where('name', 'TARJADOR')->count());
    }

    private function aceptaRol(string $rol): bool
    {
        DB::statement('SAVEPOINT sp_rol');
        try {
            DB::table('users')->insert(['name' => 'X', 'email' => 'chk' . uniqid() . '@test.local', 'password' => 'x', 'role' => $rol, 'status' => true,
                'created_at' => now(), 'updated_at' => now()]);
            DB::statement('ROLLBACK TO SAVEPOINT sp_rol');
            return true;
        } catch (\Throwable $e) {
            DB::statement('ROLLBACK TO SAVEPOINT sp_rol');
            return false;
        }
    }
}
