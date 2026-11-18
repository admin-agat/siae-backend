<?php

// 17/11/2026: pruebas de la FASE 0 de liquidación (docs/modulos/pagos-liquidaciones.md §3.1, §3.3, §3.4, §8.1 R1–R3, §10).
//   RECIBIDAS = EMBARCADAS − CAÍDAS − RECHAZADAS − FALTANTES; una LQ por contrato y una de SPOT; retención IR por LQ.
// Base: siae_test con DatabaseTransactions (nunca RefreshDatabase). Cada prueba arma sus datos.

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderLine;
use App\Models\Farm;
use App\Models\Port;
use App\Models\ProducerAdvance;
use App\Models\ProducerQuota;
use App\Models\ProducerQuotaLine;
use App\Models\PurchaseBlock;
use App\Models\Settlement;
use App\Models\SettlementWeekParameter;
use App\Models\Shipment;
use App\Models\ShippingLine;
use App\Models\TallySheet;
use App\Models\ThirdParty;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LiquidacionFase0Test extends TestCase
{
    use DatabaseTransactions;

    private static int $n = 0;

    private User $admin;
    private CustomerOrder $pedido;
    private ThirdParty $prod;
    private Farm $finca;
    private Warehouse $bodega;
    private Port $puerto;
    private Customer $cliente;
    private Brand $marca;
    private Shipment $barco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('siae_test', DB::connection()->getDatabaseName());
        $this->armar();
        Sanctum::actingAs($this->admin);
    }

    private function armar(): void
    {
        $k = ++self::$n . '-' . strtoupper(substr(uniqid(), -6));
        $this->admin = User::create(['name' => 'USUARIO ADMIN', 'email' => "admin$k@test.local", 'password' => 'Clave-Test-123', 'role' => 'ADMIN',
            'status' => true, 'must_change_password' => false, 'password_changed_at' => now()]);
        $bodeguero = User::create(['name' => 'USUARIO BODEGUERO', 'email' => "bod$k@test.local", 'password' => 'Clave-Test-123', 'role' => 'BODEGUERO',
            'status' => true, 'must_change_password' => false, 'password_changed_at' => now()]);
        $this->bodega = Warehouse::create(['name' => "BODEGA F$k", 'code' => "BF$k", 'responsible_user_id' => $bodeguero->id, 'status' => true]);
        $this->puerto = Port::create(['name' => "PUERTO F$k", 'port_type' => 'SALIDA', 'status' => true]);
        $this->prod = ThirdParty::create(['name' => "PRODUCTOR F$k", 'type' => 'PRODUCTOR', 'status' => true, 'retention_regime' => 'NORMAL']);
        $this->finca = Farm::create(['third_party_id' => $this->prod->id, 'name' => "FINCA F$k", 'magap_code' => 'C-0001', 'status' => true]);
        $clienteTercero = ThirdParty::create(['name' => "CLIENTE F$k", 'type' => 'CLIENTE', 'status' => true]);
        $this->cliente = Customer::create(['customer_code' => "CUST-F$k", 'third_party_id' => $clienteTercero->id,
            'country' => 'RUSIA', 'negotiation_type' => 'CONTRATO', 'status' => true]);
        $this->marca = Brand::create(['name' => "MARCA F$k", 'code' => "MF$k", 'status' => true]);
        $this->cliente->brands()->attach($this->marca->id);

        $this->pedido = CustomerOrder::create(['week' => 46, 'year' => 2026, 'expected_boxes' => 20000, 'status' => true]);
        CustomerOrderLine::create(['customer_order_id' => $this->pedido->id, 'customer_id' => $this->cliente->id, 'brand_id' => $this->marca->id,
            'presentation' => 'CONVENCIONAL', 'boxes' => 20000, 'incoterm' => 'FOB', 'cargo_type' => 'CAJA_BAJO_CUBIERTA', 'negotiation_type' => 'CONTRATO']);
        $linea = ShippingLine::create(['name' => "NAVIERA F$k"]);
        $this->barco = Shipment::create(['code' => "SF$k", 'registration_date' => '2026-11-09', 'departure_date' => '2026-11-20', 'vessel_name' => "NAVE F$k",
            'shipping_line_id' => $linea->id, 'week_start' => 46, 'week_end' => 46, 'year' => 2026,
            'customer_order_id' => $this->pedido->id, 'status' => true]);
    }

    // ───────────────────────── Ayudas ─────────────────────────

    private function url(string $sufijo = ''): string
    {
        return "/api/customer-orders/{$this->pedido->id}/$sufijo";
    }

    private function parametros(float $bpa = 0.01, float $minimo = 6.0): void
    {
        SettlementWeekParameter::firstOrCreate(['year' => 2026, 'week' => 46], ['minimum_price' => $minimo, 'bpa_rate' => $bpa]);
        // siae_test no trae datos: los tramos de retención (los de la migración 06/11/2026) se siembran dentro de la transacción
        if (DB::table('retention_brackets')->count() === 0) {
            foreach ([[0, 1000, 0.01], [1000, 5000, 0.0125], [5000, 20000, 0.015], [20000, 50000, 0.0175], [50000, null, 0.02]] as [$d, $h, $t]) {
                DB::table('retention_brackets')->insert(['from_boxes' => $d, 'to_boxes' => $h, 'rate' => $t, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    private function contrato(int $numero): Contract
    {
        return Contract::create(['third_party_id' => $this->prod->id, 'number' => $numero, 'price' => 7.0, 'weekly_boxes' => 5000, 'status' => true]);
    }

    /** Compra al productor (bloque único). Devuelve las líneas creadas en el orden enviado. */
    private function comprar(array $compras): array
    {
        $filas = array_map(fn ($c) => $c + [
            'third_party_id' => $this->prod->id, 'farm_id' => $this->finca->id, 'boxes' => 1000, 'official_price' => 7.0, 'real_price' => 6.0,
            'negotiation_type' => 'SPOT', 'payment_scheme' => '100',
            'pickup_warehouse_id' => $this->bodega->id, 'delivery_port_id' => $this->puerto->id,
        ], $compras);
        $version = $this->getJson($this->url('purchases'))->assertOk()->json('version');
        $bloque = PurchaseBlock::where('customer_order_id', $this->pedido->id)->where('brand_id', $this->marca->id)->value('id');
        $this->putJson($this->url('purchases'), ['version' => $version, 'blocks' => [[
            'id' => $bloque, 'shipment_id' => $this->barco->id, 'brand_id' => $this->marca->id, 'presentation' => 'CONVENCIONAL',
            'customer_id' => $this->cliente->id, 'cargo_mode' => 'CAJA_BAJO_CUBIERTA', 'target_boxes' => 20000, 'purchases' => $filas,
        ]]])->assertOk();

        $bloque = PurchaseBlock::where('customer_order_id', $this->pedido->id)->where('brand_id', $this->marca->id)->value('id');

        return ProducerQuotaLine::where('purchase_block_id', $bloque)->orderBy('id')->get()->all();
    }

    private function tarja(ProducerQuotaLine $l, int $emb, int $caidas = 0, int $rech = 0, int $falt = 0): TallySheet
    {
        return TallySheet::create(['producer_quota_line_id' => $l->id, 'shipment_id' => $this->barco->id, 'date' => '2026-11-12',
            'boxes_shipped' => $emb, 'boxes_fallen' => $caidas, 'boxes_rejected' => $rech, 'boxes_missing' => $falt]);
    }

    private function liquidar(int $cupoId, array $anticipos = []): TestResponse
    {
        return $this->postJson('/api/settlements', ['producer_quota_id' => $cupoId, 'date' => '2026-11-13', 'advances' => $anticipos]);
    }

    private function preview(int $cupoId): TestResponse
    {
        return $this->getJson("/api/settlements/preview/$cupoId")->assertOk();
    }

    /** Escenario de 3 LQ: CONTRATO 1 (2.000 recibidas), CONTRATO 2 (1.500 recibidas, 80/20) y SPOT (1.000 recibidas). */
    private function escenarioTres(): array
    {
        $this->parametros();
        $c1 = $this->contrato(1);
        $c2 = $this->contrato(2);
        [$a, $b, $s] = $this->comprar([
            ['negotiation_type' => 'CONTRATO', 'contract_id' => $c2->id, 'boxes' => 1500, 'real_price' => 8.5, 'payment_scheme' => '80_20'], // a propósito en 1.er lugar
            ['negotiation_type' => 'CONTRATO', 'contract_id' => $c1->id, 'boxes' => 2000, 'real_price' => 6.0],
            ['negotiation_type' => 'SPOT', 'boxes' => 1000, 'real_price' => 6.5],
        ]);
        // $a = contrato 2, $b = contrato 1, $s = SPOT
        $this->tarja($b, 2100, 50, 30, 20);   // 2.000 recibidas
        $this->tarja($a, 1600, 40, 30, 30);   // 1.500 recibidas
        $this->tarja($s, 1050, 20, 10, 20);   // 1.000 recibidas
        DB::table('producer_quota_lines')->where('id', $b->id)->update(['transport_recognized_rate' => 0.10]); // +200 a la LQ del contrato 1
        DB::table('producer_quota_lines')->where('id', $s->id)->update(['transport_deduction' => 100]);       // −100 a la LQ SPOT

        return [$a, $b, $s, $c1, $c2];
    }

    private function assert422(TestResponse $r, string $fragmento): void
    {
        $r->assertStatus(422);
        $mensaje = $r->json('message') ?? collect($r->json('errors') ?? [])->flatten()->implode(' | ');
        $this->assertSame(mb_strtoupper($mensaje), $mensaje, "Mensaje no está en MAYÚSCULAS: $mensaje");
        $this->assertStringContainsString($fragmento, $mensaje);
    }

    // ───────────────────────── Tarjas (§3.1) ─────────────────────────

    public function test_tarja_guarda_faltantes_y_calcula_recibidas(): void
    {
        [$l] = $this->comprar([['boxes' => 1000]]);
        $r = $this->putJson($this->url('tallies'), ['tallies' => [[
            'producer_quota_line_id' => $l->id, 'shipment_id' => $this->barco->id, 'date' => '2026-11-12',
            'boxes_shipped' => 1000, 'boxes_fallen' => 10, 'boxes_rejected' => 20, 'boxes_missing' => 30,
        ]]])->assertOk();

        $t = $r->json('tallies.0');
        $this->assertSame(30, $t['boxes_missing']);
        $this->assertSame(940, $t['boxes_received']);   // 1.000 − 10 − 20 − 30
        $this->assertSame(940, TallySheet::first()->boxes_received);
    }

    public function test_tarja_sin_faltantes_en_el_put_queda_en_cero(): void
    {
        [$l] = $this->comprar([['boxes' => 1000]]);
        $r = $this->putJson($this->url('tallies'), ['tallies' => [[
            'producer_quota_line_id' => $l->id, 'date' => '2026-11-12', 'boxes_shipped' => 500, 'boxes_fallen' => 5, 'boxes_rejected' => 5,
        ]]])->assertOk();

        $this->assertSame(0, $r->json('tallies.0.boxes_missing'));
        $this->assertSame(490, $r->json('tallies.0.boxes_received'));
    }

    public function test_tarja_con_cero_embarcadas_o_partes_mayores_dan_422(): void
    {
        [$l] = $this->comprar([['boxes' => 1000]]);
        $base = ['producer_quota_line_id' => $l->id, 'date' => '2026-11-12', 'boxes_fallen' => 0, 'boxes_rejected' => 0, 'boxes_missing' => 0];

        $r = $this->putJson($this->url('tallies'), ['tallies' => [$base + ['boxes_shipped' => 0]]]);
        $r->assertStatus(422);
        $this->assertStringContainsString('ESCRIBE LAS CAJAS RECIBIDAS', collect($r->json('errors'))->flatten()->implode(' '));

        // 40 + 40 + 30 = 110 > 100 embarcadas
        $r = $this->putJson($this->url('tallies'), ['tallies' => [['boxes_shipped' => 100, 'boxes_fallen' => 40, 'boxes_rejected' => 40, 'boxes_missing' => 30] + $base]]);
        $r->assertStatus(422);
        $msg = collect($r->json('errors'))->flatten()->implode(' ');
        $this->assertStringContainsString('NO PUEDEN SUMAR MÁS QUE LAS RECIBIDAS', $msg);
        $this->assertSame(mb_strtoupper($msg), $msg);
        $this->assertSame(0, TallySheet::where('producer_quota_line_id', $l->id)->count());

        // justo en el límite sí pasa (recibidas = 0)
        $this->putJson($this->url('tallies'), ['tallies' => [['boxes_shipped' => 100, 'boxes_fallen' => 40, 'boxes_rejected' => 30, 'boxes_missing' => 30] + $base]])->assertOk();
    }

    public function test_la_base_rechaza_tarjas_que_no_caben_check(): void
    {
        [$l] = $this->comprar([['boxes' => 1000]]);
        foreach ([[100, 60, 30, 20], [100, -1, 0, 0]] as [$e, $c, $r, $f]) {
            try {
                DB::transaction(fn () => DB::table('tally_sheets')->insert(['producer_quota_line_id' => $l->id, 'date' => '2026-11-12',
                    'boxes_shipped' => $e, 'boxes_fallen' => $c, 'boxes_rejected' => $r, 'boxes_missing' => $f, 'created_at' => now(), 'updated_at' => now()]));
                $this->fail('La base debió rechazar la tarja.');
            } catch (\Illuminate\Database\QueryException $ex) {
                $this->assertStringContainsString('check', strtolower($ex->getMessage()));
            }
        }
    }

    // ───────────────────────── Liquidación: recibidas (§3.1) ─────────────────────────

    public function test_la_liquidacion_paga_recibidas_factura_y_nc_con_maximo_cero(): void
    {
        $this->parametros();
        $this->prod->update(['retention_regime' => 'NO_RETENER']);
        [$a, $b] = $this->comprar([
            ['boxes' => 1000, 'real_price' => 6.0, 'official_price' => 7.0],
            ['boxes' => 1000, 'real_price' => 8.0, 'official_price' => 7.0],
        ]);
        $this->tarja($a, 1000, 20, 30, 50);   // 900 recibidas
        $this->tarja($b, 1000, 0, 0, 100);    // 900 recibidas

        $p = $this->preview($a->producer_quota_id);
        $this->assertSame([], $p->json('problemas'));
        $this->assertSame(2000, $p->json('boxes_shipped'));
        $this->assertSame(1800, $p->json('boxes_paid'));
        $this->assertSame(150, $p->json('boxes_missing'));  // 50 + 100
        $this->assertEquals(900 * 6 + 900 * 8, $p->json('fruit_amount'));            // sobre recibidas
        $this->assertEquals(1800 * 7, $p->json('invoice_amount'));                   // factura oficial sobre recibidas
        $this->assertEquals(900 * 7 - 900 * 6, $p->json('credit_note_amount'));      // 900 + 0 (la 2.ª: real 8 > oficial 7 → 0, no negativa)
        $this->assertEquals(0, $p->json('lines.1.credit_note_amount'));
        $this->assertSame(900, $p->json('lines.0.boxes_paid'));
    }

    public function test_transporte_negociado_no_se_descuenta_si_no_hay_recibidas_y_reconocido_va_sobre_recibidas(): void
    {
        $this->parametros();
        $this->prod->update(['retention_regime' => 'NO_RETENER']);
        [$a, $b] = $this->comprar([['boxes' => 500], ['boxes' => 500, 'negotiation_type' => 'SPOT']]);
        $this->tarja($a, 100, 100, 0, 0);     // 0 recibidas (todo cayó)
        $this->tarja($b, 400, 0, 0, 100);     // 300 recibidas
        DB::table('producer_quota_lines')->whereIn('id', [$a->id, $b->id])->update(['transport_deduction' => 50, 'transport_recognized_rate' => 0.20, 'bonus_rate' => 0.10, 'bonus_reason' => 'EMPAQUE_ESPECIAL']);

        $p = $this->preview($a->producer_quota_id);
        $this->assertEquals(0, $p->json('lines.0.transport_deduction'));          // 0 recibidas → no se descuenta
        $this->assertEquals(50, $p->json('lines.1.transport_deduction'));
        $this->assertEquals(60, $p->json('transport_recognized_amount'));         // 300 × 0,20
        $this->assertEquals(30, $p->json('bonus_amount'));                        // 300 × 0,10
        $this->assertEquals(50, $p->json('transport_amount'));
    }

    public function test_compras_sin_tarja_no_entran_en_la_liquidacion(): void
    {
        $this->parametros();
        $this->prod->update(['retention_regime' => 'NO_RETENER']);
        [$a, $b] = $this->comprar([['boxes' => 500], ['boxes' => 700]]);
        $this->tarja($a, 500);

        $p = $this->preview($a->producer_quota_id);
        $this->assertCount(1, $p->json('lines'));
        $this->assertSame($a->id, $p->json('lines.0.producer_quota_line_id'));
        $this->assertSame(500, $p->json('boxes_paid'));
        $this->assertCount(1, $p->json('groups'));
    }

    // ───────────────────────── Retención por LQ (§3.3) ─────────────────────────

    private function liquidaSpot(int $recibidas, string $regimen, float $real = 6.0, float $oficial = 7.0): TestResponse
    {
        $this->parametros(0.01, 6.0);
        $this->prod->update(['retention_regime' => $regimen]);
        [$l] = $this->comprar([['boxes' => 6000, 'real_price' => $real, 'official_price' => $oficial]]);
        $this->tarja($l, $recibidas + 100, 40, 30, 30);

        return $this->preview($l->producer_quota_id);
    }

    public function test_retencion_normal_por_tramos_4761_cajas_es_1_20_por_ciento(): void
    {
        $p = $this->liquidaSpot(4761, 'NORMAL');
        $g = $p->json('groups.0');
        $this->assertSame(4761, $g['boxes_paid']);
        $this->assertEqualsWithDelta(0.0120, (float) $g['retention_rate'], 0.00001);          // 1.000×1 % + 3.761×1,25 % = 1,1975 % → 1,20 %
        $this->assertEqualsWithDelta(4761 * 7.0, (float) $g['retention_base'], 0.01);         // max(real 6; oficial 7) = 7
        $this->assertEqualsWithDelta(round(4761 * 7.0 * 0.012, 2), (float) $g['retention_amount'], 0.01);
        $this->assertSame('NORMAL', $g['retention_regime']);
    }

    public function test_retencion_bpa_usa_la_tasa_de_la_semana_y_no_retener_es_cero(): void
    {
        $g = $this->liquidaSpot(4761, 'BPA')->json('groups.0');
        $this->assertEqualsWithDelta(0.01, (float) $g['retention_rate'], 0.00001);
        $this->assertEqualsWithDelta(round(4761 * 7.0 * 0.01, 2), (float) $g['retention_amount'], 0.01);
    }

    public function test_retencion_no_retener_es_cero(): void
    {
        $g = $this->liquidaSpot(1000, 'NO_RETENER')->json('groups.0');
        $this->assertEquals(0, $g['retention_rate']);
        $this->assertEquals(0, $g['retention_amount']);
    }

    public function test_la_base_usa_el_mayor_entre_real_y_oficial(): void
    {
        $g = $this->liquidaSpot(1000, 'NORMAL', 8.0, 7.0)->json('groups.0');   // real 8 > oficial 7
        $this->assertEqualsWithDelta(1000 * 8.0, (float) $g['retention_base'], 0.01);
        $this->assertEqualsWithDelta(80.0, (float) $g['retention_amount'], 0.01); // 1 % (≤ 1.000 cajas) × 8.000
    }

    // ───────────────────────── Varias LQ por cupo (§3.3) ─────────────────────────

    public function test_un_cupo_con_dos_contratos_y_spot_genera_tres_lq_en_un_post(): void
    {
        [$a, $b, $s, $c1, $c2] = $this->escenarioTres();
        $adv = ProducerAdvance::create(['third_party_id' => $this->prod->id, 'date' => '2026-11-01', 'type' => 'ANTICIPO', 'amount' => 500, 'concept' => 'PRUEBA', 'status' => true]);

        $p = $this->preview($a->producer_quota_id);
        $this->assertSame([], $p->json('problemas'));
        $this->assertSame(['CONTRATO 1', 'CONTRATO 2', 'SPOT'], array_column($p->json('groups'), 'group_label'));
        $this->assertSame([false, true, false], array_column($p->json('groups'), 'lleva_descuentos'));   // contrato 2 tiene más fruta (12.750)

        $r = $this->liquidar($a->producer_quota_id, [['id' => $adv->id, 'amount' => 500]])->assertStatus(201);

        $lqs = Settlement::where('producer_quota_id', $a->producer_quota_id)->orderBy('id')->get();
        $this->assertCount(3, $lqs);
        $this->assertSame(['CONTRATO 1', 'CONTRATO 2', 'SPOT'], $lqs->pluck('group_label')->all());
        $this->assertSame([$c1->id, $c2->id, null], $lqs->pluck('contract_id')->all());
        $this->assertSame(['CONTRATO', 'CONTRATO', 'SPOT'], $lqs->pluck('negotiation_type')->all());
        $this->assertSame(ProducerQuota::LIQUIDADO, ProducerQuota::find($a->producer_quota_id)->status);

        // La respuesta es la LQ que lleva descuentos (contrato 2) y trae a las hermanas
        $this->assertSame('CONTRATO 2', $r->json('group_label'));
        $this->assertEqualsCanonicalizing([$lqs[0]->id, $lqs[2]->id], array_column($r->json('hermanas'), 'id'));

        // Contrato 1: 2.000 × 6 = 12.000; retención 1,13 % de 14.000 = 158,20; transporte reconocido +200
        [$l1, $l2, $l3] = $lqs->all();
        $this->assertSame(2000, $l1->boxes_paid);
        $this->assertSame(20, $l1->boxes_missing);
        $this->assertEquals(12000.00, $l1->fruit_amount);
        $this->assertEquals(14000.00, $l1->retention_base);
        $this->assertEquals(0.0113, $l1->retention_rate);
        $this->assertEquals(158.20, $l1->retention_amount);
        $this->assertEquals(2000.00, $l1->credit_note_amount);
        $this->assertEquals(0, $l1->advances_amount);
        $this->assertEquals(12000 - 158.20 + 200, $l1->net_amount);
        $this->assertSame([1], $l1->payments()->pluck('installment')->all());         // 100 → pago único
        $this->assertEquals(12041.80, $l1->payments()->first()->amount);

        // Contrato 2: 1.500 × 8,5 = 12.750; retención 1,08 % de 12.750 = 137,70; lleva el anticipo; NC en 0
        $this->assertSame(1500, $l2->boxes_paid);
        $this->assertEquals(12750.00, $l2->fruit_amount);
        $this->assertEquals(137.70, $l2->retention_amount);
        $this->assertEquals(0, $l2->credit_note_amount);
        $this->assertEquals(500.00, $l2->advances_amount);
        $this->assertEquals(12750 - 137.70 - 500, $l2->net_amount);
        $pagos = $l2->payments()->orderBy('installment')->get();                       // 80_20 → 80 / 20
        $this->assertCount(2, $pagos);
        $this->assertEquals(2550.00, $pagos[1]->amount);
        $this->assertEquals(12750 - 2550 - 137.70 - 500, $pagos[0]->amount);
        $this->assertCount(1, $l2->advances);

        // SPOT: 1.000 × 6,5 = 6.500; retención 1 % de 7.000 = 70; transporte −100; sin anticipos ni materiales
        $this->assertSame(1000, $l3->boxes_paid);
        $this->assertEquals(70.00, $l3->retention_amount);
        $this->assertEquals(500.00, $l3->credit_note_amount);
        $this->assertEquals(0, $l3->advances_amount);
        $this->assertEquals(0, $l3->materials_amount);
        $this->assertCount(0, $l3->advances);
        $this->assertEquals(6500 - 70 - 100, $l3->net_amount);

        // Cada línea de compra quedó en su LQ con las recibidas congeladas
        $this->assertSame([$b->id], $l1->lines()->pluck('producer_quota_line_id')->all());
        $this->assertSame([$a->id], $l2->lines()->pluck('producer_quota_line_id')->all());
        $this->assertSame([$s->id], $l3->lines()->pluck('producer_quota_line_id')->all());
        $this->assertSame(1500, $l2->lines()->first()->boxes_paid);
        $this->assertSame(30, $l2->lines()->first()->boxes_missing);
    }

    public function test_el_anticipo_se_descuenta_una_sola_vez(): void
    {
        [$a] = $this->escenarioTres();
        $adv = ProducerAdvance::create(['third_party_id' => $this->prod->id, 'date' => '2026-11-01', 'type' => 'ANTICIPO', 'amount' => 500, 'concept' => 'PRUEBA', 'status' => true]);
        $this->liquidar($a->producer_quota_id, [['id' => $adv->id, 'amount' => 500]])->assertStatus(201);

        $this->assertEquals(500.00, (float) DB::table('settlement_advances')->where('producer_advance_id', $adv->id)->sum('amount'));
        $this->assertEquals(0.0, $adv->fresh()->saldo());
    }

    public function test_la_liquidacion_con_un_solo_grupo_crea_una_lq(): void
    {
        $this->parametros();
        [$a] = $this->comprar([['boxes' => 800]]);
        $this->tarja($a, 800);
        $this->liquidar($a->producer_quota_id)->assertStatus(201)->assertJsonPath('group_label', 'SPOT');
        $this->assertSame(1, Settlement::where('producer_quota_id', $a->producer_quota_id)->count());
    }

    public function test_si_una_lq_queda_en_negativo_no_se_crea_ninguna(): void
    {
        [$a] = $this->escenarioTres();
        $adv = ProducerAdvance::create(['third_party_id' => $this->prod->id, 'date' => '2026-11-01', 'type' => 'ANTICIPO', 'amount' => 99999, 'concept' => 'PRUEBA', 'status' => true]);
        $r = $this->liquidar($a->producer_quota_id, [['id' => $adv->id, 'amount' => 99999]]);

        $this->assert422($r, 'SUPERAN EL VALOR DE LA FRUTA');
        $this->assertSame(0, Settlement::where('producer_quota_id', $a->producer_quota_id)->count());
        $this->assertNotSame(ProducerQuota::LIQUIDADO, ProducerQuota::find($a->producer_quota_id)->status);
    }

    // ───────────────────────── R1, R2, R3 ─────────────────────────

    public function test_r1_compra_de_contrato_sin_contrato_da_422(): void
    {
        $this->parametros();
        [$a] = $this->comprar([['negotiation_type' => 'CONTRATO', 'boxes' => 800]]);   // el vendedor no tiene contratos: se guarda sin contract_id
        $this->assertNull($a->contract_id);
        $this->tarja($a, 800);

        $this->assertStringContainsString('ASIGNA EL CONTRATO', implode(' ', $this->preview($a->producer_quota_id)->json('problemas')));
        $this->assert422($this->liquidar($a->producer_quota_id), 'ASIGNA EL CONTRATO');
        $this->assertSame(0, Settlement::where('producer_quota_id', $a->producer_quota_id)->count());
    }

    public function test_r2_sin_precio_real_da_422(): void
    {
        $this->parametros();
        [$a] = $this->comprar([['boxes' => 800, 'real_price' => null]]);
        $this->tarja($a, 800);

        $this->assert422($this->liquidar($a->producer_quota_id), 'FALTA EL PRECIO REAL');
    }

    public function test_r3_sin_parametros_de_la_semana_da_422(): void
    {
        SettlementWeekParameter::where('year', 2026)->where('week', 46)->delete();
        [$a] = $this->comprar([['boxes' => 800]]);
        $this->tarja($a, 800);

        $this->assert422($this->liquidar($a->producer_quota_id), 'REGISTRA LOS PARÁMETROS DE LA WK46');
        $this->assertStringContainsString('REGISTRA LOS PARÁMETROS DE LA WK46', implode(' ', $this->preview($a->producer_quota_id)->json('problemas')));
        $this->assertSame(0, Settlement::where('producer_quota_id', $a->producer_quota_id)->count());
    }

    // ───────────────────────── Anular (§3.3) ─────────────────────────

    public function test_anular_una_lq_anula_todas_las_del_cupo_y_libera_anticipos(): void
    {
        [$a] = $this->escenarioTres();
        $adv = ProducerAdvance::create(['third_party_id' => $this->prod->id, 'date' => '2026-11-01', 'type' => 'ANTICIPO', 'amount' => 500, 'concept' => 'PRUEBA', 'status' => true]);
        $this->liquidar($a->producer_quota_id, [['id' => $adv->id, 'amount' => 500]])->assertStatus(201);
        $this->assertEquals(0.0, $adv->fresh()->saldo());

        $spot = Settlement::where('producer_quota_id', $a->producer_quota_id)->where('group_label', 'SPOT')->firstOrFail();
        $r = $this->patchJson("/api/settlements/{$spot->id}/annul", ['reason' => 'prueba'])->assertOk();

        $this->assertSame(0, Settlement::where('producer_quota_id', $a->producer_quota_id)->where('status', true)->count());
        $this->assertSame(3, Settlement::where('producer_quota_id', $a->producer_quota_id)->where('status', false)->count());   // soft-delete: no se borra
        $this->assertSame('PRUEBA', Settlement::where('producer_quota_id', $a->producer_quota_id)->value('cancel_reason'));
        $this->assertSame(ProducerQuota::ASIGNADO, ProducerQuota::find($a->producer_quota_id)->status);
        $this->assertEquals(500.0, $adv->fresh()->saldo());
        $this->assertFalse($r->json('status'));

        // Cada anulación quedó en la bitácora
        $ids = Settlement::where('producer_quota_id', $a->producer_quota_id)->pluck('id');
        $this->assertSame(3, AuditLog::where('table_name', 'settlements')->whereIn('record_id', $ids)->where('action', '!=', 'CREAR')->count());

        // Se puede volver a liquidar el cupo (el índice único solo cuenta las activas)
        $this->liquidar($a->producer_quota_id)->assertStatus(201);
        $this->assertSame(3, Settlement::where('producer_quota_id', $a->producer_quota_id)->where('status', true)->count());
    }

    public function test_anular_con_un_pago_registrado_en_cualquier_hermana_da_422_y_no_cambia_nada(): void
    {
        [$a] = $this->escenarioTres();
        $this->liquidar($a->producer_quota_id)->assertStatus(201);
        $lqs = Settlement::where('producer_quota_id', $a->producer_quota_id)->orderBy('id')->get();

        // 18/11/2026 (fase 2b): para pagar, el vendedor necesita cédula y cuenta bancaria
        $this->prod->update(['identification' => '1700000008']);
        $banco = \App\Models\Bank::create(['name' => 'BANCO F0 ' . uniqid(), 'code' => '9' . random_int(100, 999), 'status' => true]);
        \App\Models\ThirdPartyBankAccount::create(['third_party_id' => $this->prod->id, 'bank_id' => $banco->id, 'account_type' => 'AHORROS',
            'account_number' => '2200000001', 'holder_id_type' => 'C', 'holder_id' => '1700000008', 'holder_name' => 'PRUEBA']);
        $this->patchJson("/api/settlements/{$lqs[0]->id}/payments/1/pay", ['paid_at' => '2026-11-14'])->assertOk();
        $r = $this->patchJson("/api/settlements/{$lqs[2]->id}/annul", ['reason' => 'PRUEBA']);   // se anula desde SPOT; el pago es del contrato 1

        $this->assert422($r, $lqs[0]->code);
        $this->assertSame(3, Settlement::where('producer_quota_id', $a->producer_quota_id)->where('status', true)->count());
        $this->assertSame(ProducerQuota::LIQUIDADO, ProducerQuota::find($a->producer_quota_id)->status);

        // Al deshacer el pago ya se puede anular
        $this->patchJson("/api/settlements/{$lqs[0]->id}/payments/1/unpay")->assertOk();
        $this->patchJson("/api/settlements/{$lqs[2]->id}/annul", ['reason' => 'PRUEBA'])->assertOk();
        $this->assertSame(0, Settlement::where('producer_quota_id', $a->producer_quota_id)->where('status', true)->count());
    }

    public function test_el_indice_unico_impide_dos_lq_activas_del_mismo_grupo(): void
    {
        $this->parametros();
        [$a] = $this->comprar([['boxes' => 800]]);
        $this->tarja($a, 800);
        $this->liquidar($a->producer_quota_id)->assertStatus(201);
        $existente = Settlement::where('producer_quota_id', $a->producer_quota_id)->firstOrFail();

        try {
            DB::transaction(fn () => Settlement::create($existente->only(['producer_quota_id', 'negotiation_type', 'contract_id', 'group_label', 'third_party_id', 'week', 'year', 'date'])
                + ['code' => 'LIQ-99999999', 'boxes_shipped' => 1, 'boxes_paid' => 1, 'fruit_amount' => 1, 'net_amount' => 1, 'status' => true]));
            $this->fail('El índice único debió impedir la segunda LQ activa del mismo grupo.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('settlements_grupo_activo_unique', $e->getMessage());
        }

        // Una inactiva sí puede convivir
        Settlement::create($existente->only(['producer_quota_id', 'negotiation_type', 'contract_id', 'group_label', 'third_party_id', 'week', 'year', 'date'])
            + ['code' => 'LIQ-99999998', 'boxes_shipped' => 1, 'boxes_paid' => 1, 'fruit_amount' => 1, 'net_amount' => 1, 'status' => false]);
        $this->assertTrue(true);
    }

    // ───────────────────────── Listados y detalle (§3.4) ─────────────────────────

    public function test_quotas_devuelve_faltantes_recibidas_y_settlements_sin_claves_viejas(): void
    {
        [$a] = $this->escenarioTres();
        $this->liquidar($a->producer_quota_id)->assertStatus(201);

        $fila = collect($this->getJson('/api/settlements/quotas')->assertOk()->json())->firstWhere('id', $a->producer_quota_id);
        $this->assertNotNull($fila);
        $this->assertSame(4750, $fila['embarcadas']);        // 2.100 + 1.600 + 1.050
        $this->assertSame(110, $fila['caidas']);
        $this->assertSame(70, $fila['rechazadas']);
        $this->assertSame(70, $fila['faltantes']);
        $this->assertSame(4500, $fila['recibidas']);         // 4.750 − 110 − 70 − 70
        $this->assertArrayNotHasKey('settlement', $fila);
        $this->assertArrayNotHasKey('a_pagar', $fila);
        $this->assertSame(['CONTRATO 1', 'CONTRATO 2', 'SPOT'], array_column($fila['settlements'], 'group_label'));
        $this->assertSame([false, false, false], array_column($fila['settlements'], 'pagado'));
        $this->assertArrayHasKey('code', $fila['settlements'][0]);
        $this->assertArrayHasKey('net_amount', $fila['settlements'][0]);
        $this->assertSame(ProducerQuota::LIQUIDADO, $fila['status']);
    }

    public function test_quotas_sin_liquidar_trae_settlements_vacio(): void
    {
        $this->parametros();
        [$a] = $this->comprar([['boxes' => 500]]);
        $this->tarja($a, 500, 0, 0, 50);

        $fila = collect($this->getJson('/api/settlements/quotas')->json())->firstWhere('id', $a->producer_quota_id);
        $this->assertSame([], $fila['settlements']);
        $this->assertSame(450, $fila['recibidas']);
    }

    public function test_preview_devuelve_groups_y_settlements_y_show_devuelve_hermanas(): void
    {
        [$a] = $this->escenarioTres();
        $antes = $this->preview($a->producer_quota_id);
        $this->assertSame([], $antes->json('settlements'));
        $this->assertCount(3, $antes->json('groups'));
        foreach (['group_label', 'contract_id', 'retention_amount', 'retention_rate', 'retention_base', 'boxes_paid', 'fruit_amount'] as $k) {
            $this->assertArrayHasKey($k, $antes->json('groups.0'));
        }

        $this->liquidar($a->producer_quota_id)->assertStatus(201);
        $despues = $this->preview($a->producer_quota_id);
        $this->assertCount(3, $despues->json('settlements'));
        $this->assertContains('ESTE CUPO YA FUE LIQUIDADO.', $despues->json('problemas'));

        $lq = Settlement::where('producer_quota_id', $a->producer_quota_id)->where('group_label', 'CONTRATO 1')->firstOrFail();
        $show = $this->getJson("/api/settlements/{$lq->id}")->assertOk();
        $this->assertEqualsCanonicalizing(['CONTRATO 2', 'SPOT'], array_column($show->json('hermanas'), 'group_label'));
        $this->assertEquals(158.20, $show->json('retention_amount'));
        $this->assertSame(20, $show->json('boxes_missing'));
    }

    // ───────────────────────── Ganancia por viaje y cierre de embarque (§3.4) ─────────────────────────

    public function test_ganancia_por_viaje_usa_cajas_recibidas(): void
    {
        $this->parametros();
        [$a] = $this->comprar([['boxes' => 1000, 'real_price' => 6.0]]);
        $this->tarja($a, 1000, 10, 20, 70);   // 900 recibidas

        $r = $this->getJson('/api/voyage-profit?customer_order_id=' . $this->pedido->id)->assertOk();
        $this->assertEquals(900 * 6.0, $r->json('total.fruta'));
        $this->assertSame(900, $r->json('shipments.0.customers.0.cajas_compradas') ?? $r->json('shipments.0.clientes.0.cajas_compradas'));
    }

    public function test_cierre_de_embarque_devuelve_faltantes_y_recibidas_en_tallies(): void
    {
        $this->parametros();
        [$a] = $this->comprar([['boxes' => 1000]]);
        $this->tarja($a, 1000, 10, 20, 70);

        $t = $this->getJson($this->url('closing'))->assertOk()->json('tallies.0');
        $this->assertEquals(1000, $t['embarcadas']);      // el cruce con el INGRESO sigue contra lo bruto
        $this->assertEquals(10, $t['caidas']);
        $this->assertEquals(20, $t['rechazadas']);
        $this->assertEquals(70, $t['faltantes']);
        $this->assertEquals(900, $t['recibidas']);
    }

    // ───────────────────────── Modelos ─────────────────────────

    public function test_accesor_boxes_received_y_relacion_settlements_del_cupo(): void
    {
        [$a] = $this->comprar([['boxes' => 100]]);
        $this->assertSame(55, $this->tarja($a, 100, 10, 20, 15)->boxes_received);

        $cupo = ProducerQuota::find($a->producer_quota_id);
        $this->assertCount(0, $cupo->settlements);
        $this->assertTrue(method_exists($cupo, 'settlements'));
        $this->assertFalse(method_exists($cupo, 'settlement'));
    }

    public function test_settlement_y_tarja_guardan_boxes_missing_con_fillable_y_bitacora(): void
    {
        [$a] = $this->comprar([['boxes' => 100]]);
        $t = $this->tarja($a, 100, 0, 0, 5);
        $t->update(['boxes_missing' => 8]);
        $log = AuditLog::where('table_name', 'tally_sheets')->where('record_id', $t->id)->where('action', '!=', 'CREAR')->latest('id')->first();
        $this->assertNotNull($log, 'El cambio de faltantes debe quedar en la bitácora.');
        $this->assertStringContainsString('boxes_missing', json_encode($log->changes));
    }
}
