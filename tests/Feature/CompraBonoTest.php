<?php

// 13/11/2026: pruebas del BONO / EXTRA en compras de fruta y en la liquidación.
// Diseño: siae-frontend/docs/modulos/compra-columnas-bono.md (aprobado por Peter).
// Bono = PAGO APARTE: no toca la NC ni la retención, se paga completo en el 1er pago. NC = máx(0, oficial − real).
// Base: siae_test con DatabaseTransactions (nunca RefreshDatabase). Cada prueba arma sus datos.

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderLine;
use App\Models\Farm;
use App\Models\Port;
use App\Models\ProducerQuota;
use App\Models\ProducerQuotaLine;
use App\Models\PurchaseBlock;
use App\Models\Shipment;
use App\Models\ShippingLine;
use App\Models\TallySheet;
use App\Models\ThirdParty;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompraBonoTest extends TestCase
{
    use DatabaseTransactions;

    private static int $n = 0;

    private User $comercial;
    private CustomerOrder $pedido;
    private ThirdParty $asoc;
    private ThirdParty $prod1;
    private ThirdParty $prod2;
    private Farm $finca1;
    private Farm $finca2;
    private Warehouse $bodega;
    private Port $puerto;
    private Customer $cliente;
    /** @var Brand[] 0 = convencional A, 1 = packed, 2 = convencional B */
    private array $marcas = [];
    private Shipment $barco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('siae_test', \DB::connection()->getDatabaseName());
        $this->armar();
        Sanctum::actingAs($this->comercial);
    }

    private function armar(): void
    {
        $k = ++self::$n . '-' . strtoupper(substr(uniqid(), -6));
        $this->comercial = $this->usuario('COMERCIAL', "comercial$k@test.local");
        $bodeguero = $this->usuario('BODEGUERO', "bodeguero$k@test.local");
        $this->bodega = Warehouse::create(['name' => "BODEGA B$k", 'code' => "BB$k", 'responsible_user_id' => $bodeguero->id, 'status' => true]);
        $this->puerto = Port::create(['name' => "PUERTO B$k", 'port_type' => 'SALIDA', 'status' => true]);

        $this->asoc = ThirdParty::create(['name' => "ASOCIACION B$k", 'type' => 'COMERCIALIZADORA', 'status' => true]);
        $this->prod1 = ThirdParty::create(['name' => "PRODUCTOR UNO B$k", 'type' => 'PRODUCTOR', 'status' => true]);
        $this->prod2 = ThirdParty::create(['name' => "PRODUCTOR DOS B$k", 'type' => 'PRODUCTOR', 'status' => true]);
        $this->finca1 = Farm::create(['third_party_id' => $this->prod1->id, 'name' => "FINCA UNO B$k", 'magap_code' => 'C-0001', 'status' => true]);
        $this->finca2 = Farm::create(['third_party_id' => $this->prod2->id, 'name' => "FINCA DOS B$k", 'magap_code' => 'C-0002', 'status' => true]);

        $clienteTercero = ThirdParty::create(['name' => "CLIENTE B$k", 'type' => 'CLIENTE', 'status' => true]);
        $this->cliente = Customer::create(['customer_code' => "CUST-B$k", 'third_party_id' => $clienteTercero->id,
            'country' => 'RUSIA', 'negotiation_type' => 'CONTRATO', 'status' => true]);
        foreach (['A', 'P', 'B'] as $i => $letra) {
            $this->marcas[$i] = Brand::create(['name' => "MARCA $letra B$k", 'code' => "M{$letra}B$k", 'status' => true]);
            $this->cliente->brands()->attach($this->marcas[$i]->id);
        }

        $this->pedido = CustomerOrder::create(['week' => 46, 'year' => 2026, 'expected_boxes' => 20000, 'status' => true]);
        CustomerOrderLine::create([
            'customer_order_id' => $this->pedido->id, 'customer_id' => $this->cliente->id, 'brand_id' => $this->marcas[0]->id,
            'presentation' => 'CONVENCIONAL', 'boxes' => 20000, 'incoterm' => 'FOB', 'cargo_type' => 'CAJA_BAJO_CUBIERTA',
            'negotiation_type' => 'CONTRATO',
        ]);
        $linea = ShippingLine::create(['name' => "NAVIERA B$k"]);
        $this->barco = Shipment::create([
            'code' => "SB$k", 'registration_date' => '2026-11-09', 'departure_date' => '2026-11-20', 'vessel_name' => "NAVE B$k",
            'shipping_line_id' => $linea->id, 'week_start' => 46, 'week_end' => 46, 'year' => 2026,
            'customer_order_id' => $this->pedido->id, 'status' => true,
        ]);
    }

    private function usuario(string $rol, string $email): User
    {
        return User::create(['name' => "USUARIO $rol", 'email' => $email, 'password' => 'Clave-Test-123', 'role' => $rol,
            'status' => true, 'must_change_password' => false, 'password_changed_at' => now()]);
    }

    private function url(string $sufijo = ''): string
    {
        return "/api/customer-orders/{$this->pedido->id}/purchases$sufijo";
    }

    private function version(): string
    {
        return $this->getJson($this->url())->assertOk()->json('version');
    }

    /** Compra directa al productor 1 (con finca), lista para guardar. */
    private function compra(array $extra = []): array
    {
        return $extra + [
            'third_party_id' => $this->prod1->id, 'farm_id' => $this->finca1->id, 'boxes' => 7000,
            'official_price' => 7.5, 'real_price' => 7.1, 'negotiation_type' => 'SPOT', 'payment_scheme' => '100',
            'pickup_warehouse_id' => $this->bodega->id, 'delivery_port_id' => $this->puerto->id,
        ];
    }

    private function idBloque(int $i): ?int
    {
        return PurchaseBlock::where('customer_order_id', $this->pedido->id)->where('brand_id', $this->marcas[$i]->id)->value('id');
    }

    private function bloques(array $c0, array $c1 = [], array $c2 = []): array
    {
        $def = [[0, 'CONVENCIONAL', $c0], [1, 'PACKED', $c1], [2, 'CONVENCIONAL', $c2]];
        return array_map(fn ($d) => [
            'id' => $this->idBloque($d[0]), 'shipment_id' => $this->barco->id, 'brand_id' => $this->marcas[$d[0]]->id,
            'presentation' => $d[1], 'customer_id' => $this->cliente->id, 'cargo_mode' => 'CAJA_BAJO_CUBIERTA',
            'target_boxes' => 7000, 'purchases' => $d[2],
        ], $def);
    }

    private function guardar(array $c0, array $c1 = [], array $c2 = []): TestResponse
    {
        return $this->putJson($this->url(), ['version' => $this->version(), 'blocks' => $this->bloques($c0, $c1, $c2)]);
    }

    private function bono(array $extra = []): array
    {
        return $extra + ['bonus_rate' => 0.10, 'bonus_reason' => 'EMPAQUE_ESPECIAL'];
    }

    private function lineaBloque(int $i): ProducerQuotaLine
    {
        return ProducerQuotaLine::where('purchase_block_id', $this->idBloque($i))->orderBy('id')->firstOrFail();
    }

    private function assert422(TestResponse $r, string $fragmento): void
    {
        $r->assertStatus(422);
        $mensajes = collect($r->json('errors') ?? [])->flatten();
        $this->assertNotEmpty($mensajes, 'La respuesta 422 no trae errors.');
        foreach ($mensajes as $m) {
            $this->assertSame(mb_strtoupper($m), $m, "Mensaje no está en MAYÚSCULAS: $m");
        }
        $this->assertTrue($mensajes->contains(fn ($m) => str_contains($m, $fragmento)),
            "Falta '$fragmento' en: " . $mensajes->implode(' | '));
    }

    private function admin(): User
    {
        return $this->usuario('ADMIN', 'admin' . self::$n . strtolower(uniqid()) . '@test.local');
    }

    // ───────────────────────── Validaciones del bono ─────────────────────────

    public function test_bono_sin_motivo_da_422(): void
    {
        $r = $this->guardar([$this->compra(['bonus_rate' => 0.10])]);
        $this->assert422($r, 'ELIGE EL MOTIVO DEL BONO');
        $this->assertSame(0, ProducerQuotaLine::whereIn('purchase_block_id', PurchaseBlock::where('customer_order_id', $this->pedido->id)->pluck('id'))->count());
    }

    public function test_motivo_sin_bono_da_422(): void
    {
        $this->assert422($this->guardar([$this->compra(['bonus_reason' => 'OTRO', 'bonus_detail' => 'X'])]), 'ESCRIBE EL BONO POR CAJA O QUITA EL MOTIVO.');
    }

    public function test_motivo_otro_sin_detalle_da_422(): void
    {
        $this->assert422($this->guardar([$this->compra($this->bono(['bonus_reason' => 'OTRO']))]), 'ESCRIBE EL DETALLE DEL BONO (MOTIVO OTRO).');
        $this->assert422($this->guardar([$this->compra($this->bono(['bonus_reason' => 'OTRO', 'bonus_detail' => '   ']))]), 'ESCRIBE EL DETALLE DEL BONO (MOTIVO OTRO).');
    }

    public function test_cinta_en_bloque_convencional_da_422(): void
    {
        $this->assert422($this->guardar([$this->compra($this->bono(['bonus_reason' => 'CINTA']))]), 'EL BONO POR CINTA SOLO VA EN BLOQUES PACKED.');
    }

    public function test_bono_cero_o_mayor_a_cinco_o_motivo_invalido_da_422(): void
    {
        $this->assert422($this->guardar([$this->compra($this->bono(['bonus_rate' => 0]))]), 'EL BONO DEBE SER MAYOR A 0 Y HASTA $ 5,00 POR CAJA.');
        $this->assert422($this->guardar([$this->compra($this->bono(['bonus_rate' => 5.01]))]), 'EL BONO DEBE SER MAYOR A 0 Y HASTA $ 5,00 POR CAJA.');
        $this->assert422($this->guardar([$this->compra($this->bono(['bonus_reason' => 'REGALO']))]), 'EL MOTIVO DEL BONO DEBE SER CINTA');
    }

    // ───────────────────────── Guardado ─────────────────────────

    public function test_guarda_bono_con_detalle_en_mayusculas_y_lo_devuelve(): void
    {
        $this->guardar([$this->compra($this->bono(['bonus_reason' => 'OTRO', 'bonus_detail' => 'caja reforzada con divisor']))])->assertOk();

        $l = $this->lineaBloque(0);
        $this->assertEquals(0.10, (float) $l->bonus_rate);
        $this->assertSame('OTRO', $l->bonus_reason);
        $this->assertSame('CAJA REFORZADA CON DIVISOR', $l->bonus_detail);

        $c = $this->getJson($this->url())->assertOk()->json('blocks.0.purchases.0');
        $this->assertEquals(0.10, (float) $c['bonus_rate']);
        $this->assertSame('OTRO', $c['bonus_reason']);
        $this->assertSame('CAJA REFORZADA CON DIVISOR', $c['bonus_detail']);
    }

    public function test_cinta_en_bloque_packed_se_guarda(): void
    {
        $this->guardar([], [$this->compra($this->bono(['bonus_reason' => 'CINTA', 'bonus_rate' => 0.25]))])->assertOk();
        $l = $this->lineaBloque(1);
        $this->assertSame('CINTA', $l->bonus_reason);
        $this->assertEquals(0.25, (float) $l->bonus_rate);
    }

    public function test_quitar_el_bono_lo_deja_nulo_y_el_detalle_sin_bono_se_descarta(): void
    {
        $this->guardar([$this->compra($this->bono())])->assertOk();
        $id = $this->lineaBloque(0)->id;
        $this->guardar([$this->compra(['id' => $id, 'bonus_detail' => 'SOBRA'])])->assertOk();

        $l = $this->lineaBloque(0)->fresh();
        $this->assertNull($l->bonus_rate);
        $this->assertNull($l->bonus_reason);
        $this->assertNull($l->bonus_detail);
    }

    // ───────────────────────── Candado: cupo liquidado ─────────────────────────

    public function test_cupo_liquidado_no_deja_cambiar_oficial_ni_bono(): void
    {
        $this->guardar([$this->compra($this->bono())])->assertOk();
        $l = $this->lineaBloque(0);
        $l->quota->update(['status' => ProducerQuota::LIQUIDADO]);
        $base = ['id' => $l->id];

        // Control: guardar lo mismo no se bloquea
        $this->guardar([$this->compra($this->bono($base))])->assertOk();

        $this->assert422($this->guardar([$this->compra($this->bono($base + ['official_price' => 7.9]))]), 'NO SE PUEDE MODIFICAR');
        $this->assert422($this->guardar([$this->compra($this->bono($base + ['bonus_rate' => 0.20]))]), 'NO SE PUEDE MODIFICAR');
        $this->assert422($this->guardar([$this->compra($this->bono($base + ['bonus_reason' => 'FRUTA_ADICIONAL']))]), 'NO SE PUEDE MODIFICAR');
        $this->assert422($this->guardar([$this->compra($base)]), 'NO SE PUEDE MODIFICAR'); // quitar el bono

        $l = $l->fresh();
        $this->assertEquals(7.5, (float) $l->official_price);
        $this->assertEquals(0.10, (float) $l->bonus_rate);
        $this->assertSame('EMPAQUE_ESPECIAL', $l->bonus_reason);
    }

    // ───────────────────────── Dividir ─────────────────────────

    private function dividirConBono(): ProducerQuotaLine
    {
        // Compra global de la comercializadora POR DIVIDIR, con bono
        $this->guardar([$this->compra($this->bono([
            'third_party_id' => $this->asoc->id, 'farm_id' => null, 'pending_split' => true,
        ]))])->assertOk();
        return $this->lineaBloque(0);
    }

    private function fila(int $bloque, ThirdParty $prod, Farm $finca, int $cajas, array $extra = []): array
    {
        return $extra + ['purchase_block_id' => $this->idBloque($bloque), 'producer_id' => $prod->id,
            'farm_id' => $finca->id, 'boxes' => $cajas];
    }

    public function test_dividir_copia_el_bono_a_las_filas_nuevas(): void
    {
        $original = $this->dividirConBono();
        $this->postJson($this->url("/{$original->id}/split"), ['version' => $this->version(), 'rows' => [
            $this->fila(0, $this->prod1, $this->finca1, 3000),
            $this->fila(0, $this->prod2, $this->finca2, 4000),
        ]])->assertOk();

        $lineas = ProducerQuotaLine::where('purchase_block_id', $this->idBloque(0))->get();
        $this->assertCount(2, $lineas);
        foreach ($lineas as $l) {
            $this->assertEquals(0.10, (float) $l->bonus_rate, "Fila {$l->id} sin bono.");
            $this->assertSame('EMPAQUE_ESPECIAL', $l->bonus_reason);
        }
    }

    public function test_dividir_a_packed_desde_convencional_no_copia_el_bono(): void
    {
        $original = $this->dividirConBono();
        $this->postJson($this->url("/{$original->id}/split"), ['version' => $this->version(), 'rows' => [
            $this->fila(0, $this->prod1, $this->finca1, 6000),
            $this->fila(1, $this->prod2, $this->finca2, 1000, ['real_price' => 6.25]),
        ]])->assertOk();

        $packed = ProducerQuotaLine::where('purchase_block_id', $this->idBloque(1))->firstOrFail();
        $this->assertNull($packed->bonus_rate);
        $this->assertNull($packed->bonus_reason);
        $this->assertNull($packed->bonus_detail);

        $queda = ProducerQuotaLine::where('purchase_block_id', $this->idBloque(0))->firstOrFail();
        $this->assertEquals(0.10, (float) $queda->bonus_rate);
    }

    // ───────────────────────── Liquidación ─────────────────────────

    /** Compra directa al productor 1 con tarjas: 1.000 embarcadas + 50 caídas (+ 30 rechazadas) = 1.050 pagadas. */
    private function compraConTarjas(array $extra = []): ProducerQuotaLine
    {
        $this->guardar([$this->compra($extra)])->assertOk();
        $l = $this->lineaBloque(0);
        TallySheet::create(['producer_quota_line_id' => $l->id, 'date' => '2026-11-12',
            'boxes_shipped' => 1000, 'boxes_fallen' => 50, 'boxes_rejected' => 30]);
        Sanctum::actingAs($this->admin());
        return $l;
    }

    private function liquidar(ProducerQuotaLine $l): TestResponse
    {
        return $this->postJson('/api/settlements', ['producer_quota_id' => $l->producer_quota_id, 'date' => '2026-11-13', 'advances' => []]);
    }

    public function test_preview_con_bono_calcula_bono_y_no_cambia_la_nc(): void
    {
        $l = $this->compraConTarjas($this->bono());
        $p = $this->getJson('/api/settlements/preview/' . $l->producer_quota_id)->assertOk();

        $this->assertSame([], $p->json('problemas'));
        $this->assertEquals(105.00, $p->json('bonus_amount'));            // 1.050 × 0,10
        $this->assertEquals(105.00, $p->json('lines.0.bonus_amount'));
        $this->assertEquals(7455.00, $p->json('fruit_amount'));           // 1.050 × 7,10 (sin el bono)
        $this->assertEquals(7875.00, $p->json('invoice_amount'));         // 1.050 × 7,50
        $this->assertEquals(420.00, $p->json('credit_note_amount'));      // 7.875 − 7.455: igual que sin bono
    }

    public function test_store_con_bono_neto_y_primer_pago_incluyen_el_bono(): void
    {
        $l = $this->compraConTarjas($this->bono());
        $r = $this->liquidar($l)->assertStatus(201);

        $this->assertEquals(105.00, $r->json('bonus_amount'));
        $this->assertEquals(420.00, $r->json('credit_note_amount'));
        $this->assertEquals(7455.00, $r->json('fruit_amount'));
        $this->assertEquals(7560.00, $r->json('net_amount'));             // fruta + bono
        $this->assertCount(1, $r->json('payments'));
        $this->assertEquals(7560.00, $r->json('payments.0.amount'));      // bono completo en el 1er pago
        $this->assertEquals(105.00, $r->json('lines.0.bonus_amount'));
        $this->assertSame('EMPAQUE_ESPECIAL', $r->json('lines.0.bonus_reason'));
    }

    public function test_primer_pago_80_20_lleva_todo_el_bono(): void
    {
        $l = $this->compraConTarjas($this->bono(['payment_scheme' => '80_20']));
        $r = $this->liquidar($l)->assertStatus(201);

        $this->assertEquals(7560.00, $r->json('net_amount'));
        $pagos = collect($r->json('payments'))->keyBy('installment');
        $this->assertEquals(1491.00, $pagos[2]['amount']);                // 20 % de la fruta, sin bono
        $this->assertEquals(6069.00, $pagos[1]['amount']);                // 80 % de la fruta + bono completo
    }

    public function test_nc_es_cero_cuando_el_real_supera_al_oficial(): void
    {
        $l = $this->compraConTarjas($this->bono(['real_price' => 8.0, 'official_price' => 7.5]));
        $r = $this->liquidar($l)->assertStatus(201);

        $this->assertEquals(0, $r->json('credit_note_amount'));
        $this->assertEquals(0, $r->json('lines.0.credit_note_amount'));
        $this->assertEquals(8400.00, $r->json('fruit_amount'));
        $this->assertEquals(8505.00, $r->json('net_amount'));
        $this->assertEquals(105.00, $r->json('bonus_amount'));
    }

    public function test_sin_bono_la_liquidacion_queda_igual(): void
    {
        $l = $this->compraConTarjas();
        $r = $this->liquidar($l)->assertStatus(201);

        $this->assertEquals(0, $r->json('bonus_amount'));
        $this->assertEquals(7455.00, $r->json('net_amount'));
        $this->assertEquals(420.00, $r->json('credit_note_amount'));
    }
}
