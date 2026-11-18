<?php

// 17/11/2026: pruebas de TARJAS CON HISTORIAL (fase 1a, docs/modulos/pagos-liquidaciones.md §3.5):
//   el guardado de la semana ya no borra lo que no viene, las tarjas se quitan con motivo (baja lógica),
//   conflicto 409, POST/PUT/PATCH de un camión, historial sin dinero y migración reversible.
// Base: siae_test con DatabaseTransactions (nunca RefreshDatabase). Cada prueba arma sus datos.

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderLine;
use App\Models\Farm;
use App\Models\Port;
use App\Models\ProducerQuota;
use App\Models\ProducerQuotaLine;
use App\Models\Profile;
use App\Models\PurchaseBlock;
use App\Models\SettlementWeekParameter;
use App\Models\Shipment;
use App\Models\ShippingLine;
use App\Models\TallySheet;
use App\Models\ThirdParty;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TarjasHistorialTest extends TestCase
{
    use DatabaseTransactions;

    private static int $n = 0;

    private string $k;
    private User $admin;
    private User $tarjador;
    private User $liquidador;
    private User $comercial;
    private CustomerOrder $pedido;
    private Shipment $barco;
    private ThirdParty $prod;
    private Farm $finca;
    private Customer $cliente;
    private Brand $marca;
    private Warehouse $bodega;
    private Port $puerto;
    private ProducerQuotaLine $compra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('siae_test', DB::connection()->getDatabaseName());
        $this->k = ++self::$n . '-' . strtoupper(substr(uniqid(), -6));
        $k = $this->k;
        $this->admin = $this->usuario("adm$k", 'ADMIN');
        $perfil = Profile::firstOrCreate(['name' => 'TARJADOR'], ['base_role' => 'TARJADOR', 'description' => 'TEST', 'screens' => ['tarjas'], 'status' => true]);
        $this->tarjador = $this->usuario("tar$k", 'TARJADOR', $perfil->id);
        $this->liquidador = $this->usuario("liq$k", 'LIQUIDACIONES');
        $this->comercial = $this->usuario("com$k", 'COMERCIAL');

        $bodeguero = $this->usuario("bod$k", 'BODEGUERO');
        $this->bodega = Warehouse::create(['name' => "BODEGA H$k", 'code' => "BH$k", 'responsible_user_id' => $bodeguero->id, 'status' => true]);
        $this->puerto = Port::create(['name' => "PUERTO H$k", 'port_type' => 'SALIDA', 'status' => true]);
        $this->prod = ThirdParty::create(['name' => "PRODUCTOR H$k", 'type' => 'PRODUCTOR', 'status' => true, 'retention_regime' => 'NORMAL']);
        $this->finca = Farm::create(['third_party_id' => $this->prod->id, 'name' => "FINCA H$k", 'magap_code' => 'C-0001', 'status' => true]);
        $ct = ThirdParty::create(['name' => "CLIENTE H$k", 'type' => 'CLIENTE', 'status' => true]);
        $this->cliente = Customer::create(['customer_code' => "CUST-H$k", 'third_party_id' => $ct->id, 'country' => 'RUSIA', 'negotiation_type' => 'CONTRATO', 'status' => true]);
        $this->marca = Brand::create(['name' => "MARCA H$k", 'code' => "MH$k", 'status' => true]);
        $this->cliente->brands()->attach($this->marca->id);

        [$this->pedido, $this->barco, $this->compra] = $this->semana(46);
        Sanctum::actingAs($this->tarjador);
    }

    private function usuario(string $k, string $rol, ?int $perfil = null): User
    {
        return User::create(['name' => "USUARIO $k", 'email' => "$k@test.local", 'password' => 'Clave-Test-123', 'role' => $rol,
            'profile_id' => $perfil, 'status' => true, 'must_change_password' => false, 'password_changed_at' => now()]);
    }

    /** Pedido + barco + una compra de 1.000 cajas SPOT (la hace el ADMIN). Devuelve [pedido, barco, compra]. */
    private function semana(int $week): array
    {
        $pedido = CustomerOrder::create(['week' => $week, 'year' => 2026, 'expected_boxes' => 20000, 'status' => true]);
        CustomerOrderLine::create(['customer_order_id' => $pedido->id, 'customer_id' => $this->cliente->id, 'brand_id' => $this->marca->id,
            'presentation' => 'CONVENCIONAL', 'boxes' => 20000, 'incoterm' => 'FOB', 'cargo_type' => 'CAJA_BAJO_CUBIERTA', 'negotiation_type' => 'CONTRATO']);
        $linea = ShippingLine::create(['name' => "NAVIERA H{$this->k}-$week"]);
        $barco = Shipment::create(['code' => "SH{$this->k}-$week", 'registration_date' => '2026-11-09', 'departure_date' => '2026-11-20', 'vessel_name' => "NAVE H{$this->k}-$week",
            'shipping_line_id' => $linea->id, 'week_start' => $week, 'week_end' => $week, 'year' => 2026, 'customer_order_id' => $pedido->id, 'status' => true]);

        Sanctum::actingAs($this->admin);
        $url = "/api/customer-orders/{$pedido->id}/purchases";
        $version = $this->getJson($url)->assertOk()->json('version');
        $bloque = PurchaseBlock::where('customer_order_id', $pedido->id)->where('brand_id', $this->marca->id)->value('id');
        $this->putJson($url, ['version' => $version, 'blocks' => [[
            'id' => $bloque, 'shipment_id' => $barco->id, 'brand_id' => $this->marca->id, 'presentation' => 'CONVENCIONAL',
            'customer_id' => $this->cliente->id, 'cargo_mode' => 'CAJA_BAJO_CUBIERTA', 'target_boxes' => 20000, 'purchases' => [[
                'third_party_id' => $this->prod->id, 'farm_id' => $this->finca->id, 'boxes' => 1000, 'official_price' => 7.0, 'real_price' => 6.0,
                'negotiation_type' => 'SPOT', 'payment_scheme' => '100', 'pickup_warehouse_id' => $this->bodega->id, 'delivery_port_id' => $this->puerto->id]],
        ]]])->assertOk();
        $bloque = PurchaseBlock::where('customer_order_id', $pedido->id)->where('brand_id', $this->marca->id)->value('id');
        $compra = ProducerQuotaLine::where('purchase_block_id', $bloque)->firstOrFail();

        return [$pedido, $barco, $compra];
    }

    // ───────────────────────── Ayudas ─────────────────────────

    private function urlSemana(?CustomerOrder $p = null): string
    {
        return '/api/customer-orders/' . ($p ?? $this->pedido)->id . '/tallies';
    }

    private function fila(array $extra = [], ?ProducerQuotaLine $compra = null, ?Shipment $barco = null): array
    {
        return $extra + ['producer_quota_line_id' => ($compra ?? $this->compra)->id, 'shipment_id' => ($barco ?? $this->barco)->id,
            'date' => '2026-11-12', 'boxes_shipped' => 100, 'boxes_fallen' => 1, 'boxes_rejected' => 2, 'boxes_missing' => 3];
    }

    /** Crea una tarja por POST /tallies como el usuario dado y devuelve su JSON. */
    private function crear(array $extra = [], ?User $como = null): array
    {
        Sanctum::actingAs($como ?? $this->tarjador);

        return $this->postJson('/api/tallies', $this->fila($extra))->assertStatus(201)->json();
    }

    private function tarjaDe(array $json): array
    {
        return ['id' => $json['id'], 'updated_at' => $json['updated_at']] + $this->fila(['number' => $json['number'] ?? null]);
    }

    private function mensajes(TestResponse $r): string
    {
        return ($r->json('message') ?? '') . ' | ' . collect($r->json('errors') ?? [])->flatten()->implode(' | ');
    }

    private function parametros(): void
    {
        SettlementWeekParameter::firstOrCreate(['year' => 2026, 'week' => 46], ['minimum_price' => 6.0, 'bpa_rate' => 0.01]);
        if (DB::table('retention_brackets')->count() === 0) {
            foreach ([[0, 1000, 0.01], [1000, 5000, 0.0125], [5000, 20000, 0.015], [20000, 50000, 0.0175], [50000, null, 0.02]] as [$d, $h, $t]) {
                DB::table('retention_brackets')->insert(['from_boxes' => $d, 'to_boxes' => $h, 'rate' => $t, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    private function liquidarCupo(): void
    {
        DB::table('producer_quotas')->where('id', $this->compra->producer_quota_id)->update(['status' => ProducerQuota::LIQUIDADO]);
    }

    private function clavesDeDinero($datos, string $ruta = ''): array
    {
        $malas = [];
        if (is_array($datos)) {
            foreach ($datos as $k => $v) {
                if (is_string($k) && preg_match('/price|amount|precio|monto|total|cost|costo|retention|retencion|payment|scheme|negotiation|contract|^ip$|route/i', $k)) {
                    $malas[] = "$ruta.$k";
                }
                $malas = array_merge($malas, $this->clavesDeDinero($v, "$ruta.$k"));
            }
        }
        return $malas;
    }

    // ───────────────────────── Semana sin borrado implícito ─────────────────────────

    public function test_put_de_la_semana_con_una_sola_tarja_no_quita_la_otra(): void
    {
        $a = $this->crear(['number' => 'A1']);
        $b = $this->crear(['number' => 'B1']);

        $r = $this->putJson($this->urlSemana(), ['tallies' => [$this->tarjaDe($a)]])->assertOk();
        $this->assertCount(2, $r->json('tallies'));
        $this->assertTrue(TallySheet::findOrFail($b['id'])->status);
    }

    public function test_put_de_la_semana_con_lista_vacia_no_quita_nada(): void
    {
        $this->crear(['number' => 'A1']);
        $this->crear(['number' => 'B1']);

        $r = $this->putJson($this->urlSemana(), ['tallies' => []])->assertOk();
        $this->assertCount(2, $r->json('tallies'));
        $this->assertSame(2, TallySheet::where('status', true)->where('producer_quota_line_id', $this->compra->id)->count());
    }

    public function test_removed_desactiva_con_motivo_en_mayusculas_y_no_borra(): void
    {
        $a = $this->crear(['number' => 'A1']);
        $b = $this->crear(['number' => 'B1']);

        $r = $this->putJson($this->urlSemana(), ['tallies' => [], 'removed' => [['id' => $a['id'], 'reason' => 'camión repetido']]])->assertOk();
        $this->assertCount(1, $r->json('tallies'));
        $this->assertSame($b['id'], $r->json('tallies.0.id'));
        $q = TallySheet::findOrFail($a['id']);
        $this->assertFalse($q->status);
        $this->assertSame('CAMIÓN REPETIDO', $q->deactivation_reason);
        $this->assertSame(2, TallySheet::where('producer_quota_line_id', $this->compra->id)->count(), 'no se borra');
    }

    public function test_removed_sin_motivo_da_422(): void
    {
        $a = $this->crear();
        foreach ([['id' => $a['id']], ['id' => $a['id'], 'reason' => '']] as $item) {
            $r = $this->putJson($this->urlSemana(), ['tallies' => [], 'removed' => [$item]]);
            $r->assertStatus(422);
            $this->assertStringContainsString('ESCRIBE EL MOTIVO PARA QUITAR LA TARJA.', $this->mensajes($r));
        }
        $this->assertTrue(TallySheet::findOrFail($a['id'])->status);
    }

    public function test_edicion_y_quitada_en_el_mismo_put(): void
    {
        $a = $this->crear(['number' => 'A1']);
        $b = $this->crear(['number' => 'B1']);
        $cambio = ['boxes_shipped' => 250] + $this->tarjaDe($a);
        $r = $this->putJson($this->urlSemana(), ['tallies' => [$cambio], 'removed' => [['id' => $b['id'], 'reason' => 'ERROR']]])->assertOk();
        $this->assertSame(250, TallySheet::findOrFail($a['id'])->boxes_shipped);
        $this->assertFalse(TallySheet::findOrFail($b['id'])->status);
        $this->assertCount(1, $r->json('tallies'));
    }

    // ───────────────────────── Conflicto 409 ─────────────────────────

    public function test_put_con_updated_at_viejo_da_409_la_cambio(): void
    {
        $a = $this->crear(['number' => 'A1']);
        $r = $this->putJson($this->urlSemana(), ['tallies' => [['boxes_shipped' => 300, 'updated_at' => '2020-01-01T00:00:00.000000Z'] + $this->tarjaDe($a)]]);
        $r->assertStatus(409);
        $this->assertStringContainsString('LA CAMBIÓ', $this->mensajes($r));
        $this->assertSame(100, TallySheet::findOrFail($a['id'])->boxes_shipped, 'no se guardó nada');
    }

    public function test_put_sin_updated_at_en_tarja_existente_da_409(): void
    {
        $a = $this->crear(['number' => 'A1']);
        $fila = $this->tarjaDe($a);
        unset($fila['updated_at']);
        $this->putJson($this->urlSemana(), ['tallies' => [$fila]])->assertStatus(409);
    }

    public function test_tarja_quitada_por_otra_persona_da_409_la_quito(): void
    {
        $a = $this->crear(['number' => 'A1']);
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/tallies/{$a['id']}/deactivate", ['reason' => 'otro'])->assertOk();

        Sanctum::actingAs($this->liquidador);
        $r = $this->putJson($this->urlSemana(), ['tallies' => [['boxes_shipped' => 300] + $this->tarjaDe($a)]]);
        $r->assertStatus(409);
        $this->assertStringContainsString('LA QUITÓ', $this->mensajes($r));
        $this->assertStringContainsString($this->admin->name, $this->mensajes($r));
    }

    public function test_conflicto_no_guarda_las_demas_filas_del_mismo_put(): void
    {
        $a = $this->crear(['number' => 'A1']);
        $b = $this->crear(['number' => 'B1']);
        $this->putJson($this->urlSemana(), ['tallies' => [
            ['boxes_shipped' => 500] + $this->tarjaDe($b),
            ['boxes_shipped' => 300, 'updated_at' => '2020-01-01T00:00:00Z'] + $this->tarjaDe($a),
        ]])->assertStatus(409);
        $this->assertSame(100, TallySheet::findOrFail($b['id'])->boxes_shipped);
    }

    // ───────────────────────── POST /tallies ─────────────────────────

    public function test_post_crea_la_tarja(): void
    {
        $r = $this->postJson('/api/tallies', $this->fila(['number' => 'x9', 'truck_plate' => 'abc-123']))->assertStatus(201);
        $this->assertSame('X9', $r->json('number'));
        $this->assertSame('ABC-123', $r->json('truck_plate'));
        $this->assertSame(94, $r->json('boxes_received'));
        $this->assertTrue(TallySheet::findOrFail($r->json('id'))->status);
        $this->assertSame($this->tarjador->id, TallySheet::findOrFail($r->json('id'))->created_by);
    }

    public function test_post_con_el_mismo_client_key_no_duplica(): void
    {
        $key = (string) Str::uuid();
        $a = $this->postJson('/api/tallies', $this->fila(['client_key' => $key]))->assertStatus(201)->json();
        $b = $this->postJson('/api/tallies', $this->fila(['client_key' => $key]))->assertSuccessful()->json();
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame(1, TallySheet::where('producer_quota_line_id', $this->compra->id)->count());
        // un client_key que no es UUID se rechaza
        $this->postJson('/api/tallies', $this->fila(['client_key' => 'no-es-uuid']))->assertStatus(422);
    }

    public function test_post_con_compra_de_otra_semana_da_422(): void
    {
        [$pedido2, $barco2, $compra2] = $this->semana(47);
        Sanctum::actingAs($this->tarjador);

        // barco de otra semana que la compra
        $r = $this->postJson('/api/tallies', $this->fila([], $this->compra, $barco2));
        $this->assertSame(422, $r->status());
        $this->assertStringContainsString('NO ES DE ESTA SEMANA', $this->mensajes($r));
        // compra que no existe
        $r = $this->postJson('/api/tallies', $this->fila(['producer_quota_line_id' => 999999999]));
        $this->assertSame(422, $r->status());
        $this->assertStringContainsString('LA COMPRA NO ES DE ESTA SEMANA', $this->mensajes($r));
        // la semana 47 con su propio barco sí
        $this->postJson('/api/tallies', $this->fila([], $compra2, $barco2))->assertStatus(201);
        $this->assertSame(1, TallySheet::count());
    }

    public function test_post_sin_embarcadas_o_con_partes_mayores_da_422_en_mayusculas(): void
    {
        $r = $this->postJson('/api/tallies', $this->fila(['boxes_shipped' => 0]));
        $this->assertSame(422, $r->status());
        $this->assertStringContainsString('ESCRIBE LAS CAJAS RECIBIDAS', $this->mensajes($r));
        $this->assertSame(mb_strtoupper($this->mensajes($r)), $this->mensajes($r));

        $r = $this->postJson('/api/tallies', $this->fila(['boxes_shipped' => 10, 'boxes_fallen' => 5, 'boxes_rejected' => 5, 'boxes_missing' => 5]));
        $this->assertSame(422, $r->status());
        $this->assertStringContainsString('NO PUEDEN SUMAR MÁS QUE LAS RECIBIDAS', $this->mensajes($r));
        $this->assertSame(0, TallySheet::count());
    }

    public function test_post_en_cupo_liquidado_da_422(): void
    {
        $this->liquidarCupo();
        $r = $this->postJson('/api/tallies', $this->fila());
        $this->assertSame(422, $r->status());
        $this->assertStringContainsString('YA FUE LIQUIDADO', $this->mensajes($r));
    }

    // ───────────────────────── PUT /tallies/{id} ─────────────────────────

    public function test_put_de_un_camion_con_updated_at_correcto_funciona(): void
    {
        $a = $this->crear(['number' => 'A1']);
        $r = $this->putJson("/api/tallies/{$a['id']}", ['boxes_shipped' => 400, 'updated_at' => $a['updated_at']] + $this->tarjaDe($a))->assertOk();
        $this->assertSame(400, $r->json('boxes_shipped'));
        $this->assertSame(400, TallySheet::findOrFail($a['id'])->boxes_shipped);
    }

    public function test_put_de_un_camion_con_updated_at_viejo_o_sin_el_da_409(): void
    {
        $a = $this->crear(['number' => 'A1']);
        $r = $this->putJson("/api/tallies/{$a['id']}", ['boxes_shipped' => 400, 'updated_at' => '2020-01-01T00:00:00Z'] + $this->tarjaDe($a));
        $r->assertStatus(409);
        $this->assertStringContainsString('LA CAMBIÓ', $this->mensajes($r));
        $fila = $this->fila(['boxes_shipped' => 400]);
        $this->putJson("/api/tallies/{$a['id']}", $fila)->assertStatus(409);
        $this->assertSame(100, TallySheet::findOrFail($a['id'])->boxes_shipped);
    }

    public function test_put_de_un_camion_a_una_compra_de_otra_semana_da_422(): void
    {
        $a = $this->crear(['number' => 'A1']);
        [, $barco2, $compra2] = $this->semana(47);
        Sanctum::actingAs($this->tarjador);
        $r = $this->putJson("/api/tallies/{$a['id']}", $this->fila([], $compra2, $barco2) + ['updated_at' => $a['updated_at']]);
        $this->assertSame(422, $r->status());
        $this->assertStringContainsString('NO ES DE ESTA SEMANA', $this->mensajes($r));
        $this->assertSame($this->compra->id, TallySheet::findOrFail($a['id'])->producer_quota_line_id);
    }

    public function test_put_de_un_camion_quitado_da_409_si_otro_la_quito(): void
    {
        $a = $this->crear(['number' => 'A1']);
        $this->patchJson("/api/tallies/{$a['id']}/deactivate", ['reason' => 'X'])->assertOk();
        $r = $this->putJson("/api/tallies/{$a['id']}", $this->tarjaDe($a));
        $r->assertStatus(409);
        $this->assertStringContainsString('LA QUITÓ', $this->mensajes($r));
    }

    public function test_put_de_un_camion_en_cupo_liquidado_da_422(): void
    {
        $a = $this->crear(['number' => 'A1']);
        $this->liquidarCupo();
        $actual = TallySheet::findOrFail($a['id']);
        $r = $this->putJson("/api/tallies/{$a['id']}", ['boxes_shipped' => 400, 'updated_at' => $actual->updated_at->toIso8601String()] + $this->tarjaDe($a));
        $this->assertSame(422, $r->status());
        $this->assertStringContainsString('YA FUE LIQUIDADO', $this->mensajes($r));
    }

    // ───────────────────────── deactivate / reactivate ─────────────────────────

    public function test_deactivate_y_reactivate_funcionan(): void
    {
        $a = $this->crear(['number' => 'A1']);
        $r = $this->patchJson("/api/tallies/{$a['id']}/deactivate", ['reason' => 'repetida'])->assertOk();
        $this->assertFalse($r->json('status'));
        $this->assertSame('REPETIDA', $r->json('deactivation_reason'));

        $r = $this->patchJson("/api/tallies/{$a['id']}/reactivate")->assertOk();
        $this->assertTrue($r->json('status'));
        $this->assertNull($r->json('deactivation_reason'));
    }

    public function test_deactivate_sin_motivo_da_422(): void
    {
        $a = $this->crear();
        $r = $this->patchJson("/api/tallies/{$a['id']}/deactivate", []);
        $r->assertStatus(422);
        $this->assertStringContainsString('ESCRIBE EL MOTIVO PARA QUITAR LA TARJA.', $this->mensajes($r));
        $r = $this->patchJson("/api/tallies/{$a['id']}/deactivate", ['reason' => '   ']);
        $r->assertStatus(422);
        $this->assertTrue(TallySheet::findOrFail($a['id'])->status);
    }

    public function test_deactivate_y_reactivate_se_bloquean_si_el_cupo_esta_liquidado(): void
    {
        $a = $this->crear(['number' => 'A1']);
        $b = $this->crear(['number' => 'B1']);
        $this->patchJson("/api/tallies/{$b['id']}/deactivate", ['reason' => 'ANTES'])->assertOk();
        $this->liquidarCupo();

        $r = $this->patchJson("/api/tallies/{$a['id']}/deactivate", ['reason' => 'TARDE']);
        $this->assertSame(422, $r->status());
        $this->assertStringContainsString('YA FUE LIQUIDADO', $this->mensajes($r));
        $this->assertTrue(TallySheet::findOrFail($a['id'])->status);

        $r = $this->patchJson("/api/tallies/{$b['id']}/reactivate");
        $this->assertSame(422, $r->status());
        $this->assertStringContainsString('YA FUE LIQUIDADO', $this->mensajes($r));
        $this->assertFalse(TallySheet::findOrFail($b['id'])->status);

        // y por la semana
        $r = $this->putJson($this->urlSemana(), ['tallies' => [], 'removed' => [['id' => $a['id'], 'reason' => 'X']]]);
        $this->assertSame(422, $r->status());
        $this->assertTrue(TallySheet::findOrFail($a['id'])->status);
    }

    public function test_quitar_dos_veces_no_duplica_el_evento(): void
    {
        $a = $this->crear(['number' => 'A1']);
        $this->patchJson("/api/tallies/{$a['id']}/deactivate", ['reason' => 'UNO'])->assertOk();
        $this->patchJson("/api/tallies/{$a['id']}/deactivate", ['reason' => 'DOS'])->assertOk();
        $this->assertSame('UNO', TallySheet::findOrFail($a['id'])->deactivation_reason);
        $this->assertSame(1, AuditLog::where('table_name', 'tally_sheets')->where('record_id', $a['id'])->where('action', 'EDITAR')->count());
    }

    public function test_la_base_exige_motivo_al_quitar_check(): void
    {
        $a = $this->crear();
        try {
            DB::transaction(fn () => DB::table('tally_sheets')->where('id', $a['id'])->update(['status' => false]));
            $this->fail('La base debió exigir el motivo.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('tally_sheets_motivo_check', $e->getMessage());
        }
    }

    // ───────────────────────── Tarjas quitadas no suman ─────────────────────────

    /** Dos tarjas: 600 activas y 400 quitadas (sin caídas). */
    private function conUnaQuitada(): void
    {
        $this->crear(['number' => 'A1', 'boxes_shipped' => 600, 'boxes_fallen' => 0, 'boxes_rejected' => 0, 'boxes_missing' => 0]);
        $b = $this->crear(['number' => 'B1', 'boxes_shipped' => 400, 'boxes_fallen' => 0, 'boxes_rejected' => 0, 'boxes_missing' => 0]);
        $this->patchJson("/api/tallies/{$b['id']}/deactivate", ['reason' => 'ERROR'])->assertOk();
    }

    public function test_el_preview_de_liquidacion_ignora_las_quitadas(): void
    {
        $this->parametros();
        $this->conUnaQuitada();
        Sanctum::actingAs($this->liquidador);
        $p = $this->getJson("/api/settlements/preview/{$this->compra->producer_quota_id}")->assertOk();
        $this->assertSame(600, $p->json('boxes_shipped'));
        $this->assertSame(600, $p->json('boxes_paid'));
        $this->assertEquals(600 * 6, $p->json('fruit_amount'));

        // sin tarjas activas: no se puede liquidar
        foreach (TallySheet::where('status', true)->get() as $t) {
            $t->update(['status' => false, 'deactivation_reason' => 'TODAS']);
        }
        $p = $this->getJson("/api/settlements/preview/{$this->compra->producer_quota_id}")->assertOk();
        $this->assertStringContainsString('NO HAY TARJAS REGISTRADAS', implode(' ', $p->json('problemas')));
    }

    public function test_liquidar_usa_solo_las_activas(): void
    {
        $this->parametros();
        $this->conUnaQuitada();
        Sanctum::actingAs($this->liquidador);
        $r = $this->postJson('/api/settlements', ['producer_quota_id' => $this->compra->producer_quota_id, 'date' => '2026-11-13', 'advances' => []])->assertStatus(201);
        $this->assertEquals(600 * 6, $r->json('fruit_amount'));
    }

    public function test_el_cierre_de_embarque_ignora_las_quitadas(): void
    {
        $this->conUnaQuitada();
        Sanctum::actingAs($this->admin);
        $r = $this->getJson("/api/customer-orders/{$this->pedido->id}/closing")->assertOk();
        $fila = collect($r->json('tallies'))->firstWhere('shipment_id', $this->barco->id);
        $this->assertSame(600, (int) $fila['embarcadas']);
        $this->assertSame(600, (int) $fila['recibidas']);
    }

    public function test_la_ganancia_por_viaje_ignora_las_quitadas(): void
    {
        $this->conUnaQuitada();
        Sanctum::actingAs($this->admin);
        $r = $this->getJson("/api/voyage-profit?customer_order_id={$this->pedido->id}")->assertOk();
        $c = collect($r->json('shipments.0.customers'))->firstWhere('customer_id', $this->cliente->id);
        $this->assertSame(600, $c['cajas_compradas'], 'la compra es de 1000 pero solo cuentan 600 recibidas');
        $this->assertEquals(600 * 6.0, $c['fruta']);
    }

    public function test_una_compra_con_tarja_quitada_si_se_puede_dividir_y_con_tarja_activa_no(): void
    {
        $a = $this->crear(['number' => 'A1']);
        $finca2 = Farm::create(['third_party_id' => $this->prod->id, 'name' => "FINCA2 H{$this->k}", 'magap_code' => 'C-0002', 'status' => true]);
        Sanctum::actingAs($this->admin);
        $url = "/api/customer-orders/{$this->pedido->id}/purchases/{$this->compra->id}/split";
        $cuerpo = fn () => ['version' => $this->getJson("/api/customer-orders/{$this->pedido->id}/purchases")->json('version'), 'rows' => [
            ['purchase_block_id' => $this->compra->purchase_block_id, 'farm_id' => $this->finca->id, 'boxes' => 500],
            ['purchase_block_id' => $this->compra->purchase_block_id, 'farm_id' => $finca2->id, 'boxes' => 500],
        ]];

        $r = $this->postJson($url, $cuerpo());
        $this->assertSame(422, $r->status());
        $this->assertStringContainsString('LA COMPRA YA TIENE TARJAS', $this->mensajes($r));

        $this->patchJson("/api/tallies/{$a['id']}/deactivate", ['reason' => 'ERROR'])->assertOk();
        $this->postJson($url, $cuerpo())->assertSuccessful();
        $this->assertSame(2, ProducerQuotaLine::where('producer_quota_id', $this->compra->producer_quota_id)->count());
    }

    public function test_compra_con_solo_tarjas_quitadas_se_puede_quitar_o_cambiar_en_compra_de_fruta(): void
    {
        $a = $this->crear(['number' => 'A1']);
        $this->patchJson("/api/tallies/{$a['id']}/deactivate", ['reason' => 'ERROR'])->assertOk();
        Sanctum::actingAs($this->admin);
        $r = $this->getJson("/api/customer-orders/{$this->pedido->id}/purchases")->assertOk();
        $c = collect($r->json('purchases') ?? $r->json('blocks.0.purchases') ?? [])->first();
        $this->assertNotNull($c);
        $this->assertFalse((bool) ($c['con_tarjas'] ?? null), 'con_tarjas debe ser falso si solo hay quitadas');
    }

    public function test_la_pantalla_de_tarjas_trae_solo_activas(): void
    {
        $this->conUnaQuitada();
        $r = $this->getJson($this->urlSemana())->assertOk();
        $this->assertCount(1, $r->json('tallies'));
        $this->assertSame('A1', $r->json('tallies.0.number'));
    }

    // ───────────────────────── Historial ─────────────────────────

    public function test_historial_de_una_tarja_trae_crear_editar_quitar_y_volver_a_poner(): void
    {
        $a = $this->crear(['number' => 'A1', 'boxes_rejected' => 2]);                       // tarjador
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/tallies/{$a['id']}", ['boxes_rejected' => 20, 'updated_at' => $a['updated_at']] + $this->tarjaDe($a))->assertOk();
        $this->patchJson("/api/tallies/{$a['id']}/deactivate", ['reason' => 'repetida'])->assertOk();
        Sanctum::actingAs($this->liquidador);
        $this->patchJson("/api/tallies/{$a['id']}/reactivate")->assertOk();

        $r = $this->getJson("/api/tallies/{$a['id']}/history")->assertOk();
        $ev = collect($r->json('eventos'));
        $this->assertSame(['CREADA', 'EDITADA', 'QUITADA', 'VUELTA A PONER'], $ev->pluck('tipo')->all());
        $this->assertSame([$this->tarjador->name, $this->admin->name, $this->admin->name, $this->liquidador->name], $ev->pluck('usuario')->all());

        $editada = collect($ev[1]['detalle'])->firstWhere('campo', 'RECHAZADAS');
        $this->assertSame(['antes' => '2', 'despues' => '20'], ['antes' => $editada['antes'], 'despues' => $editada['despues']]);
        $quitada = collect($ev[2]['detalle'])->firstWhere('campo', 'MOTIVO');
        $this->assertSame('REPETIDA', $quitada['despues']);
        $creada = collect($ev[0]['detalle'])->firstWhere('campo', 'RECIBIDAS');
        $this->assertSame('100', $creada['valor']);
        $this->assertSame('A1', $ev[0]['tarja']);
        $barco = collect($ev[0]['detalle'])->firstWhere('campo', 'BARCO');
        $this->assertStringContainsString("NAVE H{$this->k}", $barco['valor']);
        $compra = collect($ev[0]['detalle'])->firstWhere('campo', 'COMPRA');
        $this->assertStringContainsString($this->prod->name, $compra['valor']);
    }

    public function test_historial_de_la_compra_trae_todas_las_tarjas_incluida_una_borrada_antes(): void
    {
        $a = $this->crear(['number' => 'A1']);
        $b = $this->crear(['number' => 'B1']);
        $this->patchJson("/api/tallies/{$b['id']}/deactivate", ['reason' => 'ERROR'])->assertOk();
        // tarja borrada antes de la fase 1a: solo existe en la bitácora
        AuditLog::create(['user_id' => $this->admin->id, 'user_name' => 'USUARIO ANTIGUO', 'action' => 'BORRAR', 'table_name' => 'tally_sheets',
            'record_id' => 987654321, 'changes' => ['producer_quota_line_id' => $this->compra->id, 'number' => 'VIEJA-1', 'date' => '2026-11-10',
                'boxes_shipped' => 300, 'boxes_fallen' => 0, 'real_price' => 9.99, 'created_by' => 5]]);
        // y una borrada de OTRA compra: no debe aparecer
        AuditLog::create(['user_name' => 'X', 'action' => 'BORRAR', 'table_name' => 'tally_sheets', 'record_id' => 987654322,
            'changes' => ['producer_quota_line_id' => $this->compra->id + 100000, 'number' => 'AJENA', 'boxes_shipped' => 1]]);

        Sanctum::actingAs($this->liquidador);
        $r = $this->getJson("/api/tallies/history?producer_quota_line_id={$this->compra->id}")->assertOk();
        $ev = collect($r->json('eventos'));
        $this->assertContains('A1', $ev->pluck('tarja')->all());
        $this->assertContains('B1', $ev->pluck('tarja')->all());
        $borrada = $ev->firstWhere('tipo', 'BORRADA');
        $this->assertNotNull($borrada);
        $this->assertSame('VIEJA-1', $borrada['tarja']);
        $this->assertSame('USUARIO ANTIGUO', $borrada['usuario']);
        $this->assertNotContains('AJENA', $ev->pluck('tarja')->all());
        $this->assertContains('QUITADA', $ev->pluck('tipo')->all());
        $this->assertStringContainsString($this->prod->name, $r->json('compra'));

        // la historia de una sola tarja no mezcla la borrada
        $h = $this->getJson("/api/tallies/{$a['id']}/history")->assertOk();
        $this->assertNotContains('VIEJA-1', collect($h->json('eventos'))->pluck('tarja')->all());
    }

    public function test_historial_trae_liquidada_y_liquidacion_anulada_sin_montos(): void
    {
        $this->parametros();
        $a = $this->crear(['number' => 'A1', 'boxes_shipped' => 1000, 'boxes_fallen' => 0, 'boxes_rejected' => 0, 'boxes_missing' => 0]);
        Sanctum::actingAs($this->liquidador);
        $lq = $this->postJson('/api/settlements', ['producer_quota_id' => $this->compra->producer_quota_id, 'date' => '2026-11-13', 'advances' => []])->assertStatus(201);

        $r = $this->getJson("/api/tallies/{$a['id']}/history")->assertOk();
        $liq = collect($r->json('eventos'))->firstWhere('tipo', 'LIQUIDADA');
        $this->assertNotNull($liq);
        $this->assertSame($lq->json('code'), $liq['detalle'][0]['valor']);
        $this->assertSame($this->liquidador->name, $liq['usuario']);

        $this->patchJson("/api/settlements/{$lq->json('id')}/annul", ['reason' => 'precio mal'])->assertOk();
        $r = $this->getJson("/api/tallies/history?producer_quota_line_id={$this->compra->id}")->assertOk();
        $tipos = collect($r->json('eventos'))->pluck('tipo')->all();
        $this->assertContains('LIQUIDADA', $tipos);
        $this->assertContains('LIQUIDACIÓN ANULADA', $tipos);
        $anul = collect($r->json('eventos'))->firstWhere('tipo', 'LIQUIDACIÓN ANULADA');
        $this->assertSame($this->liquidador->name, $anul['usuario']);
        $this->assertSame('PRECIO MAL', collect($anul['detalle'])->firstWhere('campo', 'MOTIVO')['valor']);
        $this->assertSame([], $this->clavesDeDinero($r->json()));
    }

    public function test_historial_no_expone_dinero_ip_ruta_ni_campos_fuera_de_la_lista_blanca(): void
    {
        $a = $this->crear(['number' => 'A1']);
        // una línea de bitácora con campos que no deben salir
        AuditLog::create(['user_id' => $this->admin->id, 'user_name' => 'USUARIO X', 'action' => 'EDITAR', 'table_name' => 'tally_sheets', 'record_id' => $a['id'],
            'changes' => ['real_price' => [1, 2], 'payment_scheme' => ['100', '80_20'], 'client_key' => ['a', 'b'], 'created_by' => [1, 2], 'boxes_fallen' => [1, 7]],
            'ip' => '203.0.113.7', 'route' => 'PUT api/tallies/123']);
        $this->patchJson("/api/tallies/{$a['id']}/deactivate", ['reason' => 'X'])->assertOk();

        $permitidos = ['FECHA', 'N° TARJA', 'PLACA', 'CONTENEDOR', 'BARCO', 'COMPRA', 'RECIBIDAS', 'CAÍDAS', 'RECHAZADAS', 'FALTANTES', 'NOTAS', 'ESTADO', 'MOTIVO', 'LIQUIDACIÓN'];
        foreach (["/api/tallies/{$a['id']}/history", "/api/tallies/history?producer_quota_line_id={$this->compra->id}"] as $url) {
            $r = $this->getJson($url)->assertOk();
            $this->assertSame([], $this->clavesDeDinero($r->json()), "claves de dinero en $url");
            $json = $r->getContent();
            foreach (['203.0.113.7', 'PUT api/tallies', '9.99', 'real_price', 'payment_scheme', 'client_key', 'created_by', '"ip"', '"route"'] as $malo) {
                $this->assertStringNotContainsString($malo, $json, "aparece $malo en $url");
            }
            foreach ($r->json('eventos') as $e) {
                $this->assertSame(['fecha', 'usuario', 'tipo', 'tarja', 'detalle'], array_keys($e));
                foreach ($e['detalle'] as $d) {
                    $this->assertContains($d['campo'], $permitidos);
                }
            }
        }
        // el evento con campos prohibidos y uno permitido solo muestra el permitido
        $ev = collect($this->getJson("/api/tallies/{$a['id']}/history")->json('eventos'))->where('usuario', 'USUARIO X')->first();
        $this->assertSame(['CAÍDAS'], collect($ev['detalle'])->pluck('campo')->all());
    }

    public function test_el_historial_lo_ve_tarjador_y_liquidador_y_otros_reciben_403(): void
    {
        $a = $this->crear();
        foreach ([$this->tarjador, $this->liquidador, $this->admin] as $u) {
            Sanctum::actingAs($u);
            $this->getJson("/api/tallies/{$a['id']}/history")->assertOk();
            $this->getJson("/api/tallies/history?producer_quota_line_id={$this->compra->id}")->assertOk();
        }
        Sanctum::actingAs($this->comercial);
        $this->assertSame(403, $this->getJson("/api/tallies/{$a['id']}/history")->status());
        $this->assertSame(403, $this->getJson("/api/tallies/history?producer_quota_line_id={$this->compra->id}")->status());
    }

    public function test_un_usuario_solo_liquidar_ve_el_historial_pero_no_cambia_tarjas(): void
    {
        // pantalla solo 'liquidar' (perfil sin tarjas): historial sí, POST/PATCH no
        $perfil = Profile::create(['name' => "SOLO LIQ {$this->k}", 'base_role' => 'LIQUIDACIONES', 'description' => 'T', 'screens' => ['liquidar'], 'status' => true]);
        $u = $this->usuario("sl{$this->k}", 'LIQUIDACIONES', $perfil->id);
        $a = $this->crear();
        Sanctum::actingAs($u);
        $this->getJson("/api/tallies/{$a['id']}/history")->assertOk();
        $this->assertSame(403, $this->postJson('/api/tallies', $this->fila())->status());
        $this->assertSame(403, $this->patchJson("/api/tallies/{$a['id']}/deactivate", ['reason' => 'X'])->status());
    }

    public function test_sin_sesion_da_401(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/tallies', $this->fila())->assertStatus(401);
    }

    // ───────────────────────── TARJADOR ─────────────────────────

    public function test_tarjador_usa_post_put_patch_e_historial_y_sigue_con_403_fuera_de_tarjas(): void
    {
        Sanctum::actingAs($this->tarjador);
        $a = $this->postJson('/api/tallies', $this->fila(['number' => 'T1']))->assertStatus(201)->json();
        $this->putJson("/api/tallies/{$a['id']}", ['boxes_shipped' => 150, 'updated_at' => $a['updated_at']] + $this->tarjaDe($a))->assertOk();
        $this->patchJson("/api/tallies/{$a['id']}/deactivate", ['reason' => 'X'])->assertOk();
        $this->patchJson("/api/tallies/{$a['id']}/reactivate")->assertOk();
        $this->getJson("/api/tallies/{$a['id']}/history")->assertOk();
        $this->getJson("/api/tallies/history?producer_quota_line_id={$this->compra->id}")->assertOk();
        $this->getJson('/api/tallies/weeks')->assertOk();

        foreach (['/api/settlements', '/api/customer-orders', "/api/customer-orders/{$this->pedido->id}/purchases", '/api/users', '/api/voyage-profit'] as $url) {
            $this->assertSame(403, $this->getJson($url)->status(), "esperaba 403 en $url");
        }
        $this->assertSame(403, $this->postJson('/api/settlements', ['producer_quota_id' => $this->compra->producer_quota_id])->status());
        $this->assertSame(403, $this->patchJson("/api/settlements/1/annul", ['reason' => 'X'])->status());
    }

    public function test_las_respuestas_del_tarjador_no_traen_dinero(): void
    {
        $a = $this->crear(['number' => 'T1']);
        foreach ([$this->getJson($this->urlSemana()), $this->putJson("/api/tallies/{$a['id']}", $this->tarjaDe($a)),
                     $this->patchJson("/api/tallies/{$a['id']}/deactivate", ['reason' => 'X'])] as $r) {
            $this->assertSame([], $this->clavesDeDinero($r->json()), $r->getContent());
        }
    }

    // ───────────────────────── Migración ─────────────────────────

    private function migracion()
    {
        return require base_path('database/migrations/2026_11_17_000003_tarjas_historial.php');
    }

    public function test_down_de_la_migracion_se_detiene_si_hay_tarjas_quitadas(): void
    {
        $a = $this->crear();
        $this->patchJson("/api/tallies/{$a['id']}/deactivate", ['reason' => 'X'])->assertOk();
        try {
            $this->migracion()->down();
            $this->fail('down() debió detenerse.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HAY TARJAS QUITADAS', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('tally_sheets', 'status'));
    }

    public function test_down_de_la_migracion_funciona_si_no_hay_quitadas_y_up_lo_deshace(): void
    {
        $this->crear(); // activa
        $m = $this->migracion();
        $m->down();     // PostgreSQL: el DDL va dentro de la transacción de la prueba y se revierte al terminar
        $this->assertFalse(Schema::hasColumn('tally_sheets', 'status'));
        $this->assertFalse(Schema::hasColumn('tally_sheets', 'client_key'));
        $m->up();
        $this->assertTrue(Schema::hasColumn('tally_sheets', 'status'));
        $this->assertSame(1, DB::table('tally_sheets')->where('status', true)->count(), 'las existentes quedan activas');
    }
}
