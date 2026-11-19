<?php

// 19/11/2026 (fase 1b-1): pruebas del PAGAR 80 % y del cambio de la liquidación.
// Diseño: siae-frontend/docs/modulos/pagos-liquidaciones.md, 13.A, 13.C.1 (puntos 1-6) y 7.7 (casos 1b).
//   PAGAR / retirar · cambios en Compra de fruta · LQ SPOT que descuenta el 80 % · tablero · Cuentas por pagar (lote y TXT).
// Base: siae_test con DatabaseTransactions (nunca RefreshDatabase). Cada prueba arma sus datos.
// Los números de cuenta y cédulas de prueba son inventados.

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bank;
use App\Models\BankPaymentBatch;
use App\Models\BankPaymentBatchItem;
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
use App\Models\ThirdPartyBankAccount;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Anticipo80PagarTest extends TestCase
{
    use DatabaseTransactions;

    private const NUMERO_CUENTA = '2200987654321'; // inventado
    private static int $n = 0;

    private User $admin;
    private User $comercial;   // COMERCIAL sin la excepción pagar_anticipo_80
    private User $asistente;   // COMERCIAL + pagar_anticipo_80
    private User $jefe;        // LIQUIDACIONES + aprobar_pagos_liquidacion
    private User $conta;       // CONTABILIDAD (pagos_banco + confirmar_pagos por tipo base)
    private CustomerOrder $pedido;
    private ThirdParty $asoc;
    private ThirdParty $prod1;
    private ThirdParty $prod2;
    private Farm $finca1;
    private Farm $finca2;
    private Warehouse $bodega;
    private Port $puerto;
    private Customer $cliente;
    private Brand $marca;
    private Shipment $barco;
    private Bank $banco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('siae_test', DB::connection()->getDatabaseName());
        $this->armar();
        Sanctum::actingAs($this->comercial);
    }

    private function armar(): void
    {
        $k = ++self::$n . '-' . strtoupper(substr(uniqid(), -6));
        $this->admin = $this->usuario('ADMIN');
        $this->comercial = $this->usuario('COMERCIAL');
        $this->asistente = $this->usuario('COMERCIAL', ['screens_added' => ['pagar_anticipo_80']]);
        $this->jefe = $this->usuario('LIQUIDACIONES', ['screens_added' => ['aprobar_pagos_liquidacion']]);
        $this->conta = $this->usuario('CONTABILIDAD');
        $bodeguero = $this->usuario('BODEGUERO');
        $this->bodega = Warehouse::create(['name' => "BODEGA P$k", 'code' => "BP$k", 'responsible_user_id' => $bodeguero->id, 'status' => true]);
        $this->puerto = Port::create(['name' => "PUERTO P$k", 'port_type' => 'SALIDA', 'status' => true]);
        $this->asoc = ThirdParty::create(['name' => "ASOCIACION P$k", 'type' => 'COMERCIALIZADORA', 'status' => true, 'retention_regime' => 'NO_RETENER']);
        $this->prod1 = ThirdParty::create(['name' => "PRODUCTOR UNO P$k", 'type' => 'PRODUCTOR', 'status' => true, 'retention_regime' => 'NO_RETENER']);
        $this->prod2 = ThirdParty::create(['name' => "PRODUCTOR DOS P$k", 'type' => 'PRODUCTOR', 'status' => true, 'retention_regime' => 'NO_RETENER']);
        $this->finca1 = Farm::create(['third_party_id' => $this->prod1->id, 'name' => "FINCA UNO P$k", 'magap_code' => 'C-0001', 'status' => true]);
        $this->finca2 = Farm::create(['third_party_id' => $this->prod2->id, 'name' => "FINCA DOS P$k", 'magap_code' => 'C-0002', 'status' => true]);
        $clienteTercero = ThirdParty::create(['name' => "CLIENTE P$k", 'type' => 'CLIENTE', 'status' => true]);
        $this->cliente = Customer::create(['customer_code' => "CUST-P$k", 'third_party_id' => $clienteTercero->id,
            'country' => 'RUSIA', 'negotiation_type' => 'CONTRATO', 'status' => true]);
        $this->marca = Brand::create(['name' => "MARCA P$k", 'code' => "MP$k", 'status' => true]);
        $this->cliente->brands()->attach($this->marca->id);
        $this->banco = Bank::create(['name' => "BANCO PRUEBA P$k", 'code' => '9' . random_int(100, 999), 'status' => true]);

        $this->pedido = CustomerOrder::create(['week' => 46, 'year' => 2026, 'expected_boxes' => 20000, 'status' => true]);
        CustomerOrderLine::create(['customer_order_id' => $this->pedido->id, 'customer_id' => $this->cliente->id, 'brand_id' => $this->marca->id,
            'presentation' => 'CONVENCIONAL', 'boxes' => 20000, 'incoterm' => 'FOB', 'cargo_type' => 'CAJA_BAJO_CUBIERTA', 'negotiation_type' => 'CONTRATO']);
        $linea = ShippingLine::create(['name' => "NAVIERA P$k"]);
        $this->barco = Shipment::create(['code' => "SP$k", 'registration_date' => '2026-11-09', 'departure_date' => '2026-11-20', 'vessel_name' => "NAVE P$k",
            'shipping_line_id' => $linea->id, 'week_start' => 46, 'week_end' => 46, 'year' => 2026,
            'customer_order_id' => $this->pedido->id, 'status' => true]);
    }

    // ───────────────────────── Ayudas ─────────────────────────

    private function usuario(string $rol, array $extra = []): User
    {
        return User::create($extra + ['name' => "USUARIO $rol", 'email' => strtolower($rol) . self::$n . uniqid() . '@test.local', 'password' => 'Clave-Test-123',
            'role' => $rol, 'status' => true, 'must_change_password' => false, 'password_changed_at' => now()]);
    }

    private function como(User $u): User
    {
        Sanctum::actingAs($u);

        return $u;
    }

    private function url(string $sufijo = ''): string
    {
        return "/api/customer-orders/{$this->pedido->id}/purchases$sufijo";
    }

    private function version(): string
    {
        return $this->getJson($this->url())->assertOk()->json('version');
    }

    /** Compra SPOT 80/20 de 1.000 cajas a $7,00 con día de corte en la WK45 (semana de pago 45). */
    private function compra(array $extra = []): array
    {
        return $extra + [
            'third_party_id' => $this->prod1->id, 'farm_id' => $this->finca1->id, 'boxes' => 1000,
            'official_price' => 7.5, 'real_price' => 7.0, 'negotiation_type' => 'SPOT', 'payment_scheme' => '80_20',
            'cut_date' => '2026-11-04',
            'pickup_warehouse_id' => $this->bodega->id, 'delivery_port_id' => $this->puerto->id,
        ];
    }

    private function idBloque(): ?int
    {
        return PurchaseBlock::where('customer_order_id', $this->pedido->id)->where('brand_id', $this->marca->id)->value('id');
    }

    private function guardar(array $compras): TestResponse
    {
        return $this->putJson($this->url(), ['version' => $this->version(), 'blocks' => [[
            'id' => $this->idBloque(), 'shipment_id' => $this->barco->id, 'brand_id' => $this->marca->id, 'presentation' => 'CONVENCIONAL',
            'customer_id' => $this->cliente->id, 'cargo_mode' => 'CAJA_BAJO_CUBIERTA', 'target_boxes' => 20000, 'purchases' => $compras,
        ]]]);
    }

    /** @return ProducerQuotaLine[] las compras del bloque, por id */
    private function lineas(): array
    {
        return ProducerQuotaLine::where('purchase_block_id', $this->idBloque())->orderBy('id')->get()->all();
    }

    /** Guarda las compras como Comercial y las deja enviadas a bodega (condición para PAGAR). */
    private function comprar(array $compras, bool $enviar = true): array
    {
        $this->como($this->comercial);
        $this->guardar($compras)->assertOk();
        $lineas = $this->lineas();
        if ($enviar) {
            foreach ($lineas as $l) {
                $l->update(['sent_to_warehouse_at' => now()]);
            }
        }

        return $lineas;
    }

    private function pagar(ProducerQuotaLine $l, ?User $quien = null): TestResponse
    {
        $this->como($quien ?? $this->asistente);

        return $this->postJson($this->url("/{$l->id}/advance-80"), ['version' => $this->version()]);
    }

    private function orden(ProducerQuotaLine $l): ?ProducerAdvance
    {
        return ProducerAdvance::where('origin', 'SPOT_80')->where('producer_quota_line_id', $l->id)->orderByDesc('id')->first();
    }

    /** Pone la orden en un estado sin pasar por las pantallas: POR_APROBAR | APROBADA | EN_BANCO | PAGADA | RECHAZADA */
    private function estado(ProducerAdvance $o, string $estado): ProducerAdvance
    {
        $aprobada = ['approval_status' => 'APROBADA', 'approved_by' => $this->jefe->id, 'approved_at' => now()];
        $o->update(match ($estado) {
            'POR_APROBAR' => ['approval_status' => 'POR_APROBAR', 'approved_by' => null, 'approved_at' => null, 'payment_status' => null],
            'RECHAZADA'   => ['approval_status' => 'RECHAZADA', 'rejection_reason' => 'PRUEBA', 'payment_status' => null],
            'APROBADA'    => $aprobada + ['payment_status' => null],
            'EN_BANCO'    => $aprobada + ['payment_status' => 'EN_BANCO', 'payment_method' => 'LOTE_BANCO'],
            'PAGADA'      => $aprobada + ['payment_status' => 'PAGADA', 'payment_method' => 'LOTE_BANCO', 'paid_at' => '2026-11-10'],
        });

        return $o->fresh();
    }

    /** Compra enviada con su orden en el estado pedido. */
    private function conOrden(string $estado = 'POR_APROBAR', array $compra = []): array
    {
        [$l] = $this->comprar([$this->compra($compra)]);
        $this->pagar($l)->assertStatus(201);

        return [$l, $this->estado($this->orden($l), $estado)];
    }

    private function tarja(ProducerQuotaLine $l, int $emb, int $caidas = 0, int $rech = 0, int $falt = 0): TallySheet
    {
        return TallySheet::create(['producer_quota_line_id' => $l->id, 'shipment_id' => $this->barco->id, 'date' => '2026-11-12',
            'boxes_shipped' => $emb, 'boxes_fallen' => $caidas, 'boxes_rejected' => $rech, 'boxes_missing' => $falt]);
    }

    private function parametros(int $semana = 46): void
    {
        SettlementWeekParameter::firstOrCreate(['year' => 2026, 'week' => $semana], ['minimum_price' => 6.0, 'bpa_rate' => 0.01]);
    }

    private function liquidar(int $cupoId, array $anticipos = []): TestResponse
    {
        $this->como($this->admin);

        return $this->postJson('/api/settlements', ['producer_quota_id' => $cupoId, 'date' => '2026-11-13', 'advances' => $anticipos]);
    }

    private function mensajes(TestResponse $r): string
    {
        return collect($r->json('errors') ?? [])->flatten()->push($r->json('message'))->filter()->implode(' | ');
    }

    private function assert422(TestResponse $r, string $fragmento): void
    {
        $r->assertStatus(422);
        $todo = $this->mensajes($r);
        $this->assertStringContainsString($fragmento, $todo);
        foreach (collect($r->json('errors') ?? [])->flatten() as $m) {
            $this->assertSame(mb_strtoupper($m), $m, "Mensaje no está en MAYÚSCULAS: $m");
        }
    }

    private function cuenta(ThirdParty $t, bool $verificada = true): ThirdPartyBankAccount
    {
        $c = ThirdPartyBankAccount::create(['third_party_id' => $t->id, 'bank_id' => $this->banco->id, 'account_type' => 'AHORROS',
            'account_number' => self::NUMERO_CUENTA, 'holder_id_type' => 'C', 'holder_id' => '17' . str_pad((string) random_int(0, 99999999), 8, '0'),
            'holder_name' => 'TITULAR DE PRUEBA']);
        if ($verificada) {
            $c->update(['verified_at' => now(), 'verified_by' => $this->conta->id]);
        }

        return $c->fresh();
    }

    // ═════════════════════════ PAGAR 80 % (13.A, 13.C.1 puntos 1-4) ═════════════════════════

    public function test_pagar_crea_la_orden_80_por_aprobar_con_codigo_ord(): void
    {
        [$l] = $this->comprar([$this->compra()]);
        $r = $this->pagar($l)->assertStatus(201);

        $o = $this->orden($l);
        $this->assertMatchesRegularExpression('/^ORD-\d{4}-\d{5}$/', $o->payment_order_code);
        $this->assertSame($o->payment_order_code, $r->json('anticipo80_code'));
        $this->assertSame('SPOT_80', $o->origin);
        $this->assertSame('POR_APROBAR', $o->approval_status);
        $this->assertNull($o->payment_status);
        $this->assertTrue((bool) $o->status);
        $this->assertSame(1000, (int) $o->boxes);
        $this->assertEquals(7.0, (float) $o->unit_price);
        $this->assertEquals(5600.00, (float) $o->amount);                  // 1.000 × 7,00 × 0,80
        $this->assertSame(45, (int) $o->scheduled_week);                   // semana ISO del día de corte
        $this->assertSame(2026, (int) $o->scheduled_year);
        $this->assertSame($this->prod1->id, (int) $o->third_party_id);
        $this->assertSame($l->producer_quota_id, (int) $o->producer_quota_id);
        $this->assertSame($this->asistente->id, (int) $o->created_by);

        // la compra muestra el estado de la orden; el monto solo lo ve quien pide o aprueba
        $c = $r->json('blocks.0.purchases.0.anticipo80');
        $this->assertSame('POR APROBAR', $c['estado']);
        $this->assertEquals(5600, $c['amount']);
        $this->como($this->comercial);
        $sin = $this->getJson($this->url())->assertOk()->json('blocks.0.purchases.0.anticipo80');
        $this->assertSame($o->payment_order_code, $sin['code']);
        $this->assertNull($sin['amount']);

        // bitácora
        $this->assertGreaterThanOrEqual(1, AuditLog::where('table_name', 'producer_advances')->where('record_id', $o->id)->count());
    }

    public function test_pagar_avisa_en_la_campanita_al_jefe_que_aprueba(): void
    {
        [$l] = $this->comprar([$this->compra()]);
        $this->pagar($l)->assertStatus(201);
        $o = $this->orden($l);

        $avisos = UserNotification::where('user_id', $this->jefe->id)->where('type', 'ANTICIPO_80')->get();
        $this->assertCount(1, $avisos);
        $this->assertSame($o->id, (int) $avisos[0]->data['producer_advance_id']);
        $this->assertNull($avisos[0]->read_at);

        // al aprobarla, el aviso queda leído
        $this->como($this->jefe);
        $this->patchJson('/api/settlement-payables/approve', ['items' => [['tipo' => 'anticipo', 'id' => $o->id]]])->assertOk();
        $this->assertNotNull($avisos[0]->fresh()->read_at);
    }

    public function test_pagar_validaciones_422_con_su_mensaje(): void
    {
        // sin envío a bodega
        [$a] = $this->comprar([$this->compra()], false);
        $this->assert422($this->pagar($a), 'PRIMERO ENVÍA LA COMPRA A BODEGA');
        $this->assertNull($this->orden($a));
    }

    public function test_pagar_sin_precio_real_o_sin_corte_da_422(): void
    {
        [$sinPrecio] = $this->comprar([$this->compra(['real_price' => null])]);
        $this->assert422($this->pagar($sinPrecio), 'FALTA EL PRECIO REAL DE LA COMPRA');
        $this->assertNull($this->orden($sinPrecio));

        $this->como($this->comercial);
        $this->guardar([$this->compra(['id' => $sinPrecio->id, 'cut_date' => null, 'real_price' => 7.0])])->assertOk();
        $this->assert422($this->pagar($sinPrecio), 'FALTA EL DÍA DE CORTE DE LA COMPRA');
    }

    public function test_pagar_contrato_o_spot_al_100_da_422(): void
    {
        [$spot100] = $this->comprar([$this->compra(['payment_scheme' => '100'])]);
        $this->assert422($this->pagar($spot100), 'SOLO LAS COMPRAS SPOT CON PAGO 80/20 TIENEN ANTICIPO');
        $this->assertNull($this->orden($spot100));
    }

    public function test_pagar_compra_de_contrato_da_422(): void
    {
        [$contrato] = $this->comprar([$this->compra(['negotiation_type' => 'CONTRATO'])]);
        $this->assert422($this->pagar($contrato), 'SOLO LAS COMPRAS SPOT CON PAGO 80/20 TIENEN ANTICIPO');
        $this->assertNull($this->orden($contrato));
    }

    public function test_pagar_con_cupo_liquidado_da_422(): void
    {
        [$l] = $this->comprar([$this->compra()]);
        $l->quota->update(['status' => ProducerQuota::LIQUIDADO]);
        $this->assert422($this->pagar($l), 'YA FUE LIQUIDADO: TODO SE PAGA EN LA LIQUIDACIÓN');
        $this->assertNull($this->orden($l));
    }

    public function test_pagar_con_la_semana_sin_guardar_da_422(): void
    {
        [$l] = $this->comprar([$this->compra()]);
        $this->como($this->asistente);
        $r = $this->postJson($this->url("/{$l->id}/advance-80"), ['version' => 'VERSION-VIEJA']);
        $this->assert422($r, 'GUARDA LA SEMANA ANTES DE PEDIR EL 80 %');
        $this->assertNull($this->orden($l));
    }

    public function test_doble_clic_en_pagar_deja_una_sola_orden(): void
    {
        [$l] = $this->comprar([$this->compra()]);
        $this->pagar($l)->assertStatus(201);
        $codigo = $this->orden($l)->payment_order_code;

        $this->assert422($this->pagar($l), "LA COMPRA YA TIENE ANTICIPO 80 % ($codigo): ESTÁ POR APROBAR");
        $this->assertSame(1, ProducerAdvance::where('origin', 'SPOT_80')->where('producer_quota_line_id', $l->id)->count());
    }

    public function test_indice_unico_impide_dos_ordenes_80_activas_en_la_misma_compra(): void
    {
        [$l, $o] = $this->conOrden();
        $this->assertSame(1, ProducerAdvance::where('origin', 'SPOT_80')->where('status', true)->where('producer_quota_line_id', $l->id)->count());
        try {
            DB::transaction(fn () => ProducerAdvance::create($o->only(['third_party_id', 'producer_quota_id', 'producer_quota_line_id', 'boxes', 'unit_price',
                'scheduled_week', 'scheduled_year', 'amount', 'date', 'type']) + ['origin' => 'SPOT_80', 'status' => true, 'approval_status' => 'POR_APROBAR']));
            $this->fail('La base debió rechazar la segunda orden 80 % activa de la misma compra.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('producer_advances_spot80_activo_unique', $e->getMessage());
        }
    }

    public function test_permiso_pagar_anticipo_80(): void
    {
        [$l] = $this->comprar([$this->compra()]);
        $v = fn () => $this->getJson($this->url())->assertOk()->json('version');

        // COMERCIAL sin la excepción, BODEGUERO y LIQUIDACIONES (sin compra_fruta) → 403
        foreach ([$this->comercial, $this->usuario('BODEGUERO'), $this->jefe, $this->conta] as $sin) {
            $this->como($sin);
            $this->postJson($this->url("/{$l->id}/advance-80"), ['version' => 'X'])->assertForbidden();
            $this->patchJson($this->url("/{$l->id}/advance-80/cancel"), ['reason' => 'PRUEBA'])->assertForbidden();
        }
        $this->assertNull($this->orden($l));

        // con la excepción, sí; ADMIN entra a todo
        $this->como($this->asistente);
        $this->postJson($this->url("/{$l->id}/advance-80"), ['version' => $v()])->assertStatus(201);
        $this->como($this->admin);
        $this->patchJson($this->url("/{$l->id}/advance-80/cancel"), ['reason' => 'PRUEBA ADMIN'])->assertOk();
    }

    public function test_la_excepcion_pagar_anticipo_80_no_aplica_a_otros_tipos_base(): void
    {
        // 'solo' => COMERCIAL: LIQUIDACIONES con la pantalla agregada a mano no la recibe
        $liq = $this->usuario('LIQUIDACIONES', ['screens_added' => ['pagar_anticipo_80']]);
        $this->assertFalse($liq->puede('pagar_anticipo_80'));
        $this->assertTrue($this->asistente->puede('pagar_anticipo_80'));

        // y un perfil de LIQUIDACIONES no se puede guardar con esa pantalla; uno de COMERCIAL sí
        $this->como($this->admin);
        $this->postJson('/api/profiles', ['name' => 'LIQ PRUEBA ' . uniqid(), 'base_role' => 'LIQUIDACIONES', 'description' => 'X', 'screens' => ['liquidar', 'pagar_anticipo_80']])
            ->assertStatus(422)->assertJsonValidationErrors('screens');
        $this->postJson('/api/profiles', ['name' => 'COM PRUEBA ' . uniqid(), 'base_role' => 'COMERCIAL', 'description' => 'X', 'screens' => ['compra_fruta', 'pagar_anticipo_80']])
            ->assertCreated();
    }

    public function test_pagar_exige_cambiar_la_clave_vigente(): void
    {
        [$l] = $this->comprar([$this->compra()]);
        $nuevo = $this->usuario('COMERCIAL', ['screens_added' => ['pagar_anticipo_80'], 'must_change_password' => true]);
        $this->como($nuevo);
        $this->postJson($this->url("/{$l->id}/advance-80"), ['version' => 'X'])
            ->assertForbidden()->assertJsonPath('codigo', 'CAMBIAR_CLAVE');
        $this->assertNull($this->orden($l));
    }

    public function test_el_80_no_sale_ni_se_edita_en_anticipos_manuales_y_el_manual_nace_ya_entregado(): void
    {
        [$l, $o] = $this->conOrden('POR_APROBAR');
        $this->como($this->usuario('LIQUIDACIONES'));

        $manual = $this->postJson('/api/producer-advances', ['third_party_id' => $this->prod1->id, 'date' => '2026-11-10', 'type' => 'ANTICIPO', 'amount' => 300])
            ->assertStatus(201);
        $m = ProducerAdvance::findOrFail($manual->json('id'));
        $this->assertSame('MANUAL', $m->origin);
        $this->assertSame('ANTERIOR', $m->approval_status);
        $this->assertSame('PAGADA', $m->payment_status);
        $this->assertEquals(300.00, $m->saldo());          // hasta la 1b-2 se descuenta como hoy

        $ids = collect($this->getJson('/api/producer-advances')->assertOk()->json())->pluck('id');
        $this->assertTrue($ids->contains($m->id));
        $this->assertFalse($ids->contains($o->id));        // el 80 % no es un anticipo manual

        $this->putJson("/api/producer-advances/{$o->id}", ['third_party_id' => $this->prod1->id, 'date' => '2026-11-10', 'type' => 'ANTICIPO', 'amount' => 1])->assertNotFound();
        $this->patchJson("/api/producer-advances/{$o->id}/deactivate")->assertNotFound();
        $this->assertTrue((bool) $o->fresh()->status);
        $this->assertEquals(5600.00, (float) $o->fresh()->amount);
    }

    // ───────────────────────── Retirar el pedido ─────────────────────────

    public function test_retirar_el_pedido_con_motivo_anula_la_orden_y_se_puede_pedir_de_nuevo(): void
    {
        [$l, $o] = $this->conOrden();
        $this->como($this->asistente);
        $url = $this->url("/{$l->id}/advance-80/cancel");

        $this->assert422($this->patchJson($url, []), 'ESCRIBE EL MOTIVO PARA RETIRAR EL PEDIDO DEL 80 %');
        $this->assertTrue((bool) $o->fresh()->status);

        $this->patchJson($url, ['reason' => 'me equivoqué'])->assertOk();
        $o = $o->fresh();
        $this->assertFalse((bool) $o->status);
        $this->assertSame('ANULADA', $o->approval_status);
        $this->assertSame('ME EQUIVOQUÉ', $o->rejection_reason);
        $this->assertNull($this->getJson($this->url())->json('blocks.0.purchases.0.anticipo80'));

        // sin orden activa no hay qué retirar
        $this->assert422($this->patchJson($url, ['reason' => 'OTRA VEZ']), 'LA COMPRA NO TIENE ANTICIPO 80 % PEDIDO');

        // pedirlo de nuevo crea otra orden (la anulada queda en la base, no se borra)
        $this->pagar($l)->assertStatus(201);
        $this->assertSame(2, ProducerAdvance::where('origin', 'SPOT_80')->where('producer_quota_line_id', $l->id)->count());
        $this->assertSame(1, ProducerAdvance::where('origin', 'SPOT_80')->where('producer_quota_line_id', $l->id)->where('status', true)->count());
    }

    public function test_no_se_retira_una_orden_aprobada_o_en_banco(): void
    {
        [$l, $o] = $this->conOrden('APROBADA');
        foreach (['APROBADA', 'EN_BANCO', 'PAGADA'] as $estado) {
            $this->estado($o, $estado);
            $this->como($this->asistente);
            $this->assert422($this->patchJson($this->url("/{$l->id}/advance-80/cancel"), ['reason' => 'PRUEBA']), 'EL 80 % YA FUE APROBADO');
            $this->assertTrue((bool) $o->fresh()->status, $estado);
        }
    }

    public function test_orden_devuelta_se_pide_de_nuevo_y_vuelve_a_por_aprobar_con_el_mismo_codigo(): void
    {
        [$l, $o] = $this->conOrden('POR_APROBAR');
        $codigo = $o->payment_order_code;
        $this->como($this->jefe);
        $this->patchJson("/api/settlement-payables/anticipo/{$o->id}/reject", ['reason' => 'precio raro'])->assertOk()->assertJsonPath('estado', 'DEVUELTA');
        $this->como($this->asistente);
        $this->assertSame('DEVUELTA', $this->getJson($this->url())->json('blocks.0.purchases.0.anticipo80.estado'));

        $this->pagar($l)->assertStatus(201);
        $o = $o->fresh();
        $this->assertSame($codigo, $o->payment_order_code);
        $this->assertSame('POR_APROBAR', $o->approval_status);
        $this->assertNull($o->rejection_reason);
        $this->assertSame(1, ProducerAdvance::where('origin', 'SPOT_80')->where('producer_quota_line_id', $l->id)->count());
    }

    // ───────────────────────── Cambios en Compra de fruta (13.A.5, punto 3) ─────────────────────────

    private function cambiar(ProducerQuotaLine $l, array $cambios): TestResponse
    {
        $this->como($this->comercial);

        return $this->guardar([$this->compra(['id' => $l->id] + $cambios)]);
    }

    public function test_cambiar_cajas_con_la_orden_por_aprobar_recalcula(): void
    {
        [$l, $o] = $this->conOrden('POR_APROBAR');
        $this->cambiar($l, ['boxes' => 1200])->assertOk();
        $o = $o->fresh();
        $this->assertSame('POR_APROBAR', $o->approval_status);
        $this->assertSame(1200, (int) $o->boxes);
        $this->assertEquals(6720.00, (float) $o->amount);   // 1.200 × 7,00 × 0,80

        $this->cambiar($l, ['boxes' => 1200, 'real_price' => 7.5])->assertOk();
        $this->assertEquals(7200.00, (float) $o->fresh()->amount);
    }

    public function test_cambiar_cajas_con_la_orden_aprobada_vuelve_a_por_aprobar_y_avisa(): void
    {
        [$l, $o] = $this->conOrden('APROBADA');
        UserNotification::where('user_id', $this->jefe->id)->where('type', 'ANTICIPO_80')->delete();
        $this->cambiar($l, ['boxes' => 900])->assertOk();

        $o = $o->fresh();
        $this->assertSame('POR_APROBAR', $o->approval_status);
        $this->assertNull($o->approved_by);
        $this->assertNull($o->approved_at);
        $this->assertEquals(5040.00, (float) $o->amount);   // 900 × 7,00 × 0,80
        $this->assertSame(1, UserNotification::where('user_id', $this->jefe->id)->where('type', 'ANTICIPO_80')->whereNull('read_at')->count());
    }

    public function test_cambiar_cajas_con_la_orden_pagada_o_en_banco_no_cambia_la_orden_y_el_tablero_avisa(): void
    {
        [$l, $o] = $this->conOrden('PAGADA');
        $this->cambiar($l, ['boxes' => 800])->assertOk();
        $o = $o->fresh();
        $this->assertEquals(5600.00, (float) $o->amount);
        $this->assertSame(1000, (int) $o->boxes);
        $this->assertSame('PAGADA', $o->payment_status);
        $this->assertSame('APROBADA', $o->approval_status);
        $this->assertSame(800, (int) $l->fresh()->quantity_cajas);        // la compra sí cambia: la fruta real manda

        $this->estado($o, 'EN_BANCO');
        $this->cambiar($l, ['boxes' => 700, 'real_price' => 7.2])->assertOk();
        $o = $o->fresh();
        $this->assertEquals(5600.00, (float) $o->amount);
        $this->assertSame('EN_BANCO', $o->payment_status);

        $this->como($this->jefe);
        $filas = $this->getJson('/api/settlement-payables/board?estado=TODAS&semana=46&anio=2026')->assertOk()->json('filas');
        $this->assertCount(1, $filas);
        $this->assertContains('COMPRA CAMBIADA DESPUÉS DE PAGAR EL 80 %: LA DIFERENCIA SE AJUSTA EN LA LIQUIDACIÓN.', $filas[0]['avisos']);
    }

    public function test_pasar_a_contrato_con_la_orden_por_aprobar_la_anula_sola(): void
    {
        [$l, $o] = $this->conOrden('POR_APROBAR');
        $this->cambiar($l, ['negotiation_type' => 'CONTRATO'])->assertOk();
        $o = $o->fresh();
        $this->assertFalse((bool) $o->status);
        $this->assertSame('ANULADA', $o->approval_status);
    }

    public function test_pasar_a_100_con_la_orden_por_aprobar_la_anula_sola(): void
    {
        [$l, $o] = $this->conOrden('POR_APROBAR');
        $this->cambiar($l, ['payment_scheme' => '100'])->assertOk();
        $this->assertFalse((bool) $o->fresh()->status);
    }

    public function test_cambiar_el_dia_de_corte_con_la_orden_por_aprobar_recalcula_la_semana_de_pago(): void
    {
        [$l, $o] = $this->conOrden('POR_APROBAR');
        $this->cambiar($l, ['cut_date' => '2026-11-11'])->assertOk(); // WK46
        $o = $o->fresh();
        $this->assertSame(46, (int) $o->scheduled_week);
        $this->assertSame('POR_APROBAR', $o->approval_status);
        $this->assertTrue((bool) $o->status);
    }

    public function test_orden_aprobada_bloquea_contrato_100_y_dia_de_corte(): void
    {
        [$l, $o] = $this->conOrden('APROBADA');
        $this->assert422($this->cambiar($l, ['negotiation_type' => 'CONTRATO']), "LA COMPRA TIENE ANTICIPO 80 % APROBADO ({$o->payment_order_code}): NO SE PUEDE CAMBIAR");
        $this->assert422($this->cambiar($l, ['payment_scheme' => '100']), 'NO SE PUEDE CAMBIAR LA NEGOCIACIÓN');
        $this->assert422($this->cambiar($l, ['cut_date' => '2026-11-11']), 'NO SE PUEDE CAMBIAR LA FECHA DE CORTE');
        $o = $o->fresh();
        $this->assertTrue((bool) $o->status);
        $this->assertSame('APROBADA', $o->approval_status);
        $this->assertSame('SPOT', $l->fresh()->negotiation_type);
    }

    public function test_orden_pagada_bloquea_contrato_100_y_dia_de_corte(): void
    {
        [$l, $o] = $this->conOrden('PAGADA');
        $this->assert422($this->cambiar($l, ['negotiation_type' => 'CONTRATO']), "EL 80 % DE ESTA COMPRA YA SE PAGÓ ({$o->payment_order_code}): NO SE PUEDE CAMBIAR");
        $this->assert422($this->cambiar($l, ['payment_scheme' => '100']), 'YA SE PAGÓ');
        $this->assert422($this->cambiar($l, ['cut_date' => '2026-11-11']), 'NO SE PUEDE CAMBIAR LA FECHA DE CORTE');
        $this->assertSame('PAGADA', $o->fresh()->payment_status);
    }

    public function test_orden_aprobada_o_pagada_bloquea_cambiar_de_vendedor(): void
    {
        [$l] = $this->conOrden('APROBADA');
        $r = $this->cambiar($l, ['third_party_id' => $this->prod2->id, 'farm_id' => $this->finca2->id]);
        $this->assert422($r, 'NO SE PUEDE CAMBIAR LA NEGOCIACIÓN, EL VENDEDOR NI LA FORMA DE PAGO');
    }

    public function test_quitar_el_envio_segun_el_estado_de_la_orden(): void
    {
        // POR APROBAR → se quita y la orden se anula sola
        [$l, $o] = $this->conOrden('POR_APROBAR');
        $this->como($this->comercial);
        $this->patchJson($this->url("/{$l->id}/unsend"))->assertOk();
        $this->assertNull($l->fresh()->sent_to_warehouse_at);
        $this->assertFalse((bool) $o->fresh()->status);

        // APROBADA → 422, el envío se queda
        $l->fresh()->update(['sent_to_warehouse_at' => now()]);
        $this->pagar($l)->assertStatus(201);
        $o2 = $this->estado($this->orden($l), 'APROBADA');
        $this->como($this->comercial);
        $this->assert422($this->patchJson($this->url("/{$l->id}/unsend")), "LA COMPRA TIENE ANTICIPO 80 % ({$o2->payment_order_code}): NO SE PUEDE QUITAR");
        $this->assertNotNull($l->fresh()->sent_to_warehouse_at);

        // PAGADA → 422 con su mensaje
        $this->estado($o2, 'PAGADA');
        $this->assert422($this->patchJson($this->url("/{$l->id}/unsend")), 'EL 80 % DE ESTA COMPRA YA SE PAGÓ');
        $this->assertNotNull($l->fresh()->sent_to_warehouse_at);
    }

    public function test_quitar_la_compra_segun_el_estado_de_la_orden(): void
    {
        // APROBADA → la compra no se quita
        [$l, $o] = $this->conOrden('APROBADA');
        $this->como($this->comercial);
        $this->assert422($this->guardar([]), "LA COMPRA TIENE ANTICIPO 80 % ({$o->payment_order_code}): NO SE PUEDE QUITAR");
        $this->assertNotNull(ProducerQuotaLine::find($l->id));

        // POR APROBAR → se quita y la orden se anula
        $this->estado($o, 'POR_APROBAR');
        $this->guardar([])->assertOk();
        $this->assertNull(ProducerQuotaLine::find($l->id));
        $o = $o->fresh();
        $this->assertFalse((bool) $o->status);
        $this->assertSame('ANULADA', $o->approval_status);
        $this->assertNull($o->producer_quota_line_id); // la FK al borrar la compra queda nula (nullOnDelete)
    }

    // ───────────────────────── Compra dividida (punto 4) ─────────────────────────

    /** Compra global SPOT 80/20 de una comercializadora (1.000 cajas, $7,00), POR DIVIDIR, con su orden 80 %. */
    private function globalConOrden(): array
    {
        [$l] = $this->comprar([$this->compra(['third_party_id' => $this->asoc->id, 'farm_id' => null, 'pending_split' => true])]);
        $this->pagar($l)->assertStatus(201);

        return [$l, $this->orden($l)];
    }

    private function dividir(ProducerQuotaLine $l, array $filas): TestResponse
    {
        $this->como($this->comercial);
        $rows = array_map(fn ($f) => ['purchase_block_id' => $this->idBloque(), 'producer_id' => $f[0]->id, 'farm_id' => $f[1]->id, 'boxes' => $f[2]]
            + (isset($f[3]) ? ['real_price' => $f[3]] : []), $filas);

        return $this->postJson($this->url("/{$l->id}/split"), ['version' => $this->version(), 'rows' => $rows]);
    }

    public function test_dividir_deja_la_orden_en_la_compra_original_y_las_partes_no_tienen_pagar(): void
    {
        [$l, $o] = $this->globalConOrden();
        $this->dividir($l, [[$this->prod1, $this->finca1, 600], [$this->prod2, $this->finca2, 400, 8.0]])->assertOk();

        $lineas = $this->lineas();
        $this->assertCount(2, $lineas);
        $hija = collect($lineas)->first(fn ($x) => $x->id !== $l->id);
        $this->assertSame($l->id, (int) $hija->split_from_line_id);
        $hija->update(['sent_to_warehouse_at' => now()]);

        // una sola orden activa; cubre la compra completa con cada fila a su precio (precio ponderado)
        $this->assertSame(1, ProducerAdvance::where('origin', 'SPOT_80')->where('status', true)->count());
        $o = $o->fresh();
        $this->assertSame($l->id, (int) $o->producer_quota_line_id);
        $this->assertSame(1000, (int) $o->boxes);
        $this->assertEquals(7.4, (float) $o->unit_price);       // (600 × 7,00 + 400 × 8,00) / 1.000
        $this->assertEquals(5920.00, (float) $o->amount);       // 7.400 × 0,80

        // la parte que salió de dividir no tiene PAGAR
        $this->assert422($this->pagar($hija), "ESTA COMPRA SALIÓ DE DIVIDIR {$o->payment_order_code}");
        $this->assertNull($this->orden($hija));
        $this->como($this->asistente);
        $this->assertTrue($this->getJson($this->url())->assertOk()->json('blocks.0.purchases.1.anticipo80.dividida'));
    }

    public function test_la_lq_spot_descuenta_el_80_de_una_compra_dividida_una_sola_vez(): void
    {
        [$l, $o] = $this->globalConOrden();
        $this->dividir($l, [[$this->prod1, $this->finca1, 600], [$this->prod2, $this->finca2, 400, 8.0]])->assertOk();
        $this->parametros();
        foreach ($this->lineas() as $x) {
            $this->tarja($x, (int) $x->quantity_cajas);
        }
        $o = $this->estado($o->fresh(), 'PAGADA');

        $r = $this->liquidar($l->producer_quota_id)->assertStatus(201);
        $this->assertEquals(7400.00, $r->json('fruit_amount'));          // 600 × 7,00 + 400 × 8,00
        $this->assertEquals(5920.00, $r->json('advance_80_amount'));
        $this->assertEquals(1480.00, $r->json('net_amount'));
        $this->assertSame(1, DB::table('settlement_advances')->where('producer_advance_id', $o->id)->count());
        $this->assertEquals(5920.00, (float) DB::table('settlement_advances')->where('producer_advance_id', $o->id)->value('amount'));
        $this->assertEquals(0.0, $o->fresh()->saldo());
    }

    // ═════════════════════════ Liquidación SPOT (13.C.1 puntos 1-5 + 7.7 caso 5) ═════════════════════════

    public function test_lq_spot_con_el_80_pagado_descuenta_y_deja_una_sola_cuota_saldo_spot(): void
    {
        [$l, $o] = $this->conOrden('PAGADA');
        $this->parametros();
        $this->tarja($l, 1000);

        $this->como($this->admin);
        $p = $this->getJson('/api/settlements/preview/' . $l->producer_quota_id)->assertOk();
        $this->assertSame([], $p->json('problemas'));
        $this->assertEquals(5600.00, $p->json('advance_80_amount'));
        $this->assertSame('PAGADA', $p->json('anticipos_80.0.estado'));
        $this->assertTrue($p->json('anticipos_80.0.descuenta'));
        // el 80 % no se ofrece para elegir a mano
        $this->assertSame([], collect($p->json('advances'))->where('origin', 'SPOT_80')->all());

        $r = $this->liquidar($l->producer_quota_id)->assertStatus(201);
        $this->assertEquals(7000.00, $r->json('fruit_amount'));
        $this->assertEquals(5600.00, $r->json('advance_80_amount'));
        $this->assertEquals(1400.00, $r->json('net_amount'));
        $this->assertEquals(500.00, $r->json('credit_note_amount'));     // oficial 7,50 − real 7,00 sobre 1.000
        $this->assertCount(1, $r->json('payments'));
        $this->assertSame('SALDO SPOT', $r->json('payments.0.description'));
        $this->assertEquals(1400.00, $r->json('payments.0.amount'));
        $this->assertSame('POR_APROBAR', $r->json('payments.0.approval_status'));
        $this->assertMatchesRegularExpression('/^ORD-\d{4}-\d{5}$/', $r->json('payments.0.payment_order_code'));
        $this->assertSame(47, (int) $r->json('payments.0.scheduled_week'));   // N+1
        $this->assertSame(2026, (int) $r->json('payments.0.scheduled_year'));

        $fila = DB::table('settlement_advances')->where('producer_advance_id', $o->id)->first();
        $this->assertNotNull($fila);
        $this->assertEquals(5600.00, (float) $fila->amount);
        $this->assertEquals(0.0, $o->fresh()->saldo());
        $this->assertSame('PAGADA', $o->fresh()->payment_status);
        $this->assertTrue((bool) $o->fresh()->status);
    }

    public function test_lq_spot_descuenta_pagada_y_en_banco_y_anula_los_demas(): void
    {
        [$a, $b, $c, $d] = $this->comprar([
            $this->compra(['boxes' => 1000, 'real_price' => 7.0]),
            $this->compra(['boxes' => 500, 'real_price' => 6.0]),
            $this->compra(['boxes' => 200, 'real_price' => 5.0]),
            $this->compra(['boxes' => 100, 'real_price' => 5.0]),
        ]);
        foreach ([$a, $b, $c, $d] as $x) {
            $this->pagar($x)->assertStatus(201);
        }
        $oa = $this->estado($this->orden($a), 'PAGADA');        // 5.600
        $ob = $this->estado($this->orden($b), 'EN_BANCO');      // 2.400
        $oc = $this->orden($c);                                  // POR APROBAR
        $od = $this->estado($this->orden($d), 'APROBADA');      // aprobada sin lote
        $this->parametros();
        foreach ([[$a, 1000], [$b, 500], [$c, 200], [$d, 100]] as [$x, $cajas]) {
            $this->tarja($x, $cajas);
        }

        $r = $this->liquidar($a->producer_quota_id)->assertStatus(201);
        $this->assertEquals(11500.00, $r->json('fruit_amount'));          // 7.000 + 3.000 + 1.000 + 500
        $this->assertEquals(8000.00, $r->json('advance_80_amount'));      // solo PAGADA + EN BANCO
        $this->assertEquals(3500.00, $r->json('net_amount'));
        $this->assertEquals(3500.00, $r->json('payments.0.amount'));
        $this->assertCount(1, $r->json('payments'));

        $this->assertEquals(0.0, $oa->fresh()->saldo());
        $this->assertEquals(0.0, $ob->fresh()->saldo());
        foreach ([$oc, $od] as $anulada) {
            $anulada = $anulada->fresh();
            $this->assertFalse((bool) $anulada->status);
            $this->assertSame('ANULADA', $anulada->approval_status);
            $this->assertSame(0, DB::table('settlement_advances')->where('producer_advance_id', $anulada->id)->count());
        }
    }

    public function test_lq_spot_con_el_80_por_aprobar_lo_anula_y_paga_todo_en_la_cuota(): void
    {
        [$l, $o] = $this->conOrden('POR_APROBAR');
        $this->parametros();
        $this->tarja($l, 1000);

        $this->como($this->admin);
        $p = $this->getJson('/api/settlements/preview/' . $l->producer_quota_id)->assertOk();
        $this->assertFalse($p->json('anticipos_80.0.descuenta'));
        $this->assertEquals(0, $p->json('advance_80_amount'));

        $r = $this->liquidar($l->producer_quota_id)->assertStatus(201);
        $this->assertEquals(7000.00, $r->json('net_amount'));
        $this->assertEquals(0, $r->json('advance_80_amount'));
        $this->assertEquals(7000.00, $r->json('payments.0.amount'));
        $o = $o->fresh();
        $this->assertFalse((bool) $o->status);
        $this->assertSame('ANULADA', $o->approval_status);
    }

    public function test_lq_no_deja_elegir_un_anticipo_80_a_mano(): void
    {
        [$l, $o] = $this->conOrden('PAGADA');
        $this->parametros();
        $this->tarja($l, 1000);
        $r = $this->liquidar($l->producer_quota_id, [['id' => $o->id, 'amount' => 100]]);
        $this->assert422($r, "EL ANTICIPO 80 % {$o->payment_order_code} NO SE ELIGE");
        $this->assertSame(0, Settlement::where('producer_quota_id', $l->producer_quota_id)->count());
    }

    public function test_lq_con_tarja_menor_que_lo_comprado_avisa_y_deja_cuota_cero_y_saldo_a_favor(): void
    {
        // 13.A.8 sin retención ni materiales: 700 embarcadas − 20 rechazadas = 680 recibidas × 7,00 = 4.760 − 5.600 = −840
        [$l, $o] = $this->conOrden('PAGADA');
        $this->parametros();
        $this->tarja($l, 700, 0, 20);

        $this->como($this->admin);
        $p = $this->getJson('/api/settlements/preview/' . $l->producer_quota_id)->assertOk();
        $this->assertTrue(collect($p->json('avisos'))->contains(fn ($a) => str_contains($a, 'RECIBIDAS (680) MENOR QUE LO COMPRADO (1.000)')));

        $r = $this->liquidar($l->producer_quota_id)->assertStatus(201);
        $this->assertEquals(4760.00, $r->json('fruit_amount'));
        $this->assertEquals(5600.00, $r->json('advance_80_amount'));
        $this->assertEquals(0, $r->json('net_amount'));
        $this->assertSame([], $r->json('payments'));                      // cuota 0: no hay cuota

        $saldo = ProducerAdvance::where('origin', 'SALDO')->where('third_party_id', $this->prod1->id)->first();
        $this->assertNotNull($saldo);
        $this->assertEquals(840.00, (float) $saldo->amount);
        $this->assertSame('ANTERIOR', $saldo->approval_status);          // no entra a las bandejas de aprobación ni al banco
        $this->assertNull($saldo->payment_status);
        $this->assertNull($saldo->payment_order_code);
        $this->assertTrue((bool) $saldo->status);
        $this->assertSame($r->json('id'), (int) $saldo->source_settlement_id);
        $this->assertEquals(840.00, $saldo->saldo());                    // se descuenta en la siguiente LQ

        // no aparece en el tablero ni en Cuentas por pagar
        $this->como($this->jefe);
        $filas = $this->getJson('/api/settlement-payables/board?estado=TODAS&negociacion=TODAS')->assertOk()->json('filas');
        $this->assertSame([$o->id], array_column($filas, 'id'));          // solo la orden del 80 %, nunca el SALDO
        $this->como($this->conta);
        $this->assertSame([], $this->getJson('/api/accounting/payables')->assertOk()->json());
    }

    public function test_el_saldo_a_favor_sale_en_la_siguiente_lq_del_vendedor_y_se_descuenta(): void
    {
        [$l] = $this->comprar([$this->compra()]);
        $this->pagar($l)->assertStatus(201);
        $this->estado($this->orden($l), 'PAGADA');
        // segunda compra del mismo vendedor en la semana siguiente (SPOT al 100 %, 1.000 × 7,00)
        $this->como($this->comercial);
        $this->guardar([$this->compra(['id' => $l->id]), $this->compra(['payment_scheme' => '100', 'purchase_week' => 47])])->assertOk();
        $b = collect($this->lineas())->first(fn ($x) => $x->id !== $l->id);
        $this->parametros(46);
        $this->parametros(47);
        $this->tarja($l, 700, 0, 20);
        $this->tarja($b, 1000);

        $primera = $this->liquidar($l->producer_quota_id)->assertStatus(201);
        $this->assertEquals(0, $primera->json('net_amount'));
        $saldo = ProducerAdvance::where('origin', 'SALDO')->firstOrFail();

        $p = $this->getJson('/api/settlements/preview/' . $b->producer_quota_id)->assertOk();
        $oferta = collect($p->json('advances'))->firstWhere('id', $saldo->id);
        $this->assertNotNull($oferta, 'El saldo a favor debe salir en la siguiente LQ del vendedor.');
        $this->assertSame('SALDO', $oferta['origin']);
        $this->assertEquals(840.00, $oferta['saldo']);

        $r = $this->liquidar($b->producer_quota_id, [['id' => $saldo->id, 'amount' => 840]])->assertStatus(201);
        $this->assertEquals(7000.00, $r->json('fruit_amount'));
        $this->assertEquals(840.00, $r->json('advances_amount'));
        $this->assertEquals(6160.00, $r->json('net_amount'));
        $this->assertEquals(0.0, $saldo->fresh()->saldo());

        // anular la primera LQ con su SALDO ya descontado → bloqueado; anulando la segunda primero, sí
        $primeraId = $primera->json('id');
        $this->assert422($this->patchJson("/api/settlements/$primeraId/annul", ['reason' => 'PRUEBA']), 'YA SE DESCONTÓ EN OTRA LIQUIDACIÓN');
        $this->assertTrue((bool) Settlement::find($primeraId)->status);
        $this->assertTrue((bool) $saldo->fresh()->status);

        $this->patchJson('/api/settlements/' . $r->json('id') . '/annul', ['reason' => 'PRUEBA'])->assertOk();
        $this->patchJson("/api/settlements/$primeraId/annul", ['reason' => 'PRUEBA'])->assertOk();
        $this->assertFalse((bool) $saldo->fresh()->status); // el SALDO se anula con su LQ
    }

    public function test_lq_bloquea_si_hay_80_pagado_y_ninguna_compra_spot_con_tarja(): void
    {
        $this->parametros();
        $contrato = Contract::create(['third_party_id' => $this->prod1->id, 'number' => 1, 'price' => 7.0, 'weekly_boxes' => 5000, 'status' => true]);
        [$spot, $cont] = $this->comprar([
            $this->compra(['boxes' => 1000]),
            $this->compra(['negotiation_type' => 'CONTRATO', 'contract_id' => $contrato->id, 'payment_scheme' => '100', 'boxes' => 500]),
        ]);
        $this->pagar($spot)->assertStatus(201);
        $this->estado($this->orden($spot), 'PAGADA');
        $this->tarja($cont, 500);   // la compra SPOT no tiene tarja

        $this->como($this->admin);
        $p = $this->getJson('/api/settlements/preview/' . $spot->producer_quota_id)->assertOk();
        $this->assertTrue(collect($p->json('problemas'))->contains(fn ($m) => str_contains($m, 'ANTICIPO 80 % PAGADO') && str_contains($m, 'NINGUNA COMPRA SPOT CON TARJA')));
        $this->assert422($this->liquidar($spot->producer_quota_id), 'NINGUNA COMPRA SPOT CON TARJA');
        $this->assertSame(0, Settlement::where('producer_quota_id', $spot->producer_quota_id)->count());
        $this->assertNotSame(ProducerQuota::LIQUIDADO, $spot->quota->fresh()->status);

        // con la tarja de la compra SPOT, ya se puede liquidar
        $this->tarja($spot, 1000);
        $this->liquidar($spot->producer_quota_id)->assertStatus(201);
    }

    public function test_lq_contrato_es_cuota_unica_y_el_offset_del_contrato_la_pasa_a_n_mas_2(): void
    {
        $this->parametros();
        $c1 = Contract::create(['third_party_id' => $this->prod1->id, 'number' => 1, 'price' => 7.0, 'weekly_boxes' => 5000, 'status' => true]);
        [$a] = $this->comprar([$this->compra(['negotiation_type' => 'CONTRATO', 'contract_id' => $c1->id, 'payment_scheme' => '100', 'real_price' => 6.0])]);
        $this->tarja($a, 1000);
        $r = $this->liquidar($a->producer_quota_id)->assertStatus(201);
        $this->assertCount(1, $r->json('payments'));
        $this->assertSame('PAGO ÚNICO (100 %)', $r->json('payments.0.description'));
        $this->assertSame(47, (int) $r->json('payments.0.scheduled_week'));     // N+1
        $this->assertSame('POR_APROBAR', $r->json('payments.0.approval_status'));

        // contrato con offset 1: N+2
        $this->patchJson('/api/settlements/' . $r->json('id') . '/annul', ['reason' => 'PRUEBA'])->assertOk();
        $c1->update(['payment_week_offset' => 1]);
        $r2 = $this->liquidar($a->producer_quota_id)->assertStatus(201);
        $this->assertSame(48, (int) $r2->json('payments.0.scheduled_week'));
    }

    public function test_lq_con_el_precio_oficial_menor_al_real_deja_nc_cero_y_con_80_pagado(): void
    {
        [$l] = $this->conOrden('PAGADA', ['official_price' => 6.5, 'real_price' => 7.0]);
        $this->parametros();
        $this->tarja($l, 1000);
        $r = $this->liquidar($l->producer_quota_id)->assertStatus(201);
        $this->assertEquals(0, $r->json('credit_note_amount'));
        $this->assertEquals(1400.00, $r->json('net_amount'));
    }

    // ───────────────────────── Anular la LQ (13.C.1 punto 3 + 7.7 caso 12) ─────────────────────────

    private function lqConOchentaPagado(): array
    {
        [$l, $o] = $this->conOrden('PAGADA');
        $this->parametros();
        $this->tarja($l, 1000);
        $r = $this->liquidar($l->producer_quota_id)->assertStatus(201);

        return [$l, $o, $r->json('id'), $r->json('payments.0.id') ?? DB::table('settlement_payments')->where('settlement_id', $r->json('id'))->value('id')];
    }

    public function test_annul_bloqueado_con_la_cuota_aprobada_o_en_el_banco(): void
    {
        [$l, $o, $liqId, $pagoId] = $this->lqConOchentaPagado();

        DB::table('settlement_payments')->where('id', $pagoId)->update(['approval_status' => 'APROBADA']);
        $this->assert422($this->patchJson("/api/settlements/$liqId/annul", ['reason' => 'PRUEBA']), 'ESTÁ APROBADA O EN EL BANCO');
        $this->assertTrue((bool) Settlement::find($liqId)->status);

        DB::table('settlement_payments')->where('id', $pagoId)->update(['payment_status' => 'EN_BANCO', 'payment_method' => 'LOTE_BANCO']);
        $this->assert422($this->patchJson("/api/settlements/$liqId/annul", ['reason' => 'PRUEBA']), 'ESTÁ APROBADA O EN EL BANCO');
        $this->assertTrue((bool) Settlement::find($liqId)->status);
        $this->assertSame(ProducerQuota::LIQUIDADO, $l->quota->fresh()->status);
    }

    public function test_annul_con_la_cuota_rechazada_anula_cuotas_y_el_80_vuelve_a_tener_saldo(): void
    {
        [$l, $o, $liqId, $pagoId] = $this->lqConOchentaPagado();
        $this->assertEquals(0.0, $o->fresh()->saldo());

        DB::table('settlement_payments')->where('id', $pagoId)->update(['approval_status' => 'RECHAZADA']);
        $this->patchJson("/api/settlements/$liqId/annul", ['reason' => 'prueba anulación'])->assertOk();

        $this->assertFalse((bool) Settlement::find($liqId)->status);
        $this->assertSame('ANULADA', DB::table('settlement_payments')->where('id', $pagoId)->value('approval_status'));
        $this->assertEquals(5600.00, $o->fresh()->saldo());               // la LQ anulada ya no lo descuenta
        $this->assertNotSame(ProducerQuota::LIQUIDADO, $l->quota->fresh()->status);
        $this->assertGreaterThanOrEqual(1, AuditLog::where('table_name', 'settlements')->where('record_id', $liqId)->where('action', '!=', 'CREAR')->count());
    }

    public function test_annul_con_la_cuota_por_aprobar_la_anula(): void
    {
        [, , $liqId, $pagoId] = $this->lqConOchentaPagado();
        $this->patchJson("/api/settlements/$liqId/annul", ['reason' => 'PRUEBA'])->assertOk();
        $this->assertSame('ANULADA', DB::table('settlement_payments')->where('id', $pagoId)->value('approval_status'));
    }

    // ═════════════════════════ Tablero y aprobación (7.7 casos 3 y 4) ═════════════════════════

    private function dosOrdenes(): array
    {
        [$a, $b] = $this->comprar([
            $this->compra(['boxes' => 1000, 'real_price' => 7.0]),
            $this->compra(['boxes' => 500, 'real_price' => 6.0, 'cut_date' => '2026-11-05']),
        ]);
        $this->pagar($a)->assertStatus(201);
        $this->pagar($b)->assertStatus(201);

        return [$this->orden($a), $this->orden($b)];
    }

    public function test_tablero_totales_precio_ponderado_y_filtros(): void
    {
        [$oa, $ob] = $this->dosOrdenes();
        $this->como($this->jefe);
        $r = $this->getJson('/api/settlement-payables/board?semana=46&anio=2026')->assertOk();

        $filas = collect($r->json('filas'));
        $this->assertCount(2, $filas);
        $t = $r->json('totales');
        $this->assertSame(2, $t['ordenes']);
        $this->assertSame($filas->sum('cajas'), $t['cajas']);
        $this->assertEquals(8000.00, $t['monto']);                        // 5.600 + 2.400
        $this->assertEquals($filas->sum('monto'), $t['monto']);
        $this->assertEquals(6.6667, $t['precio']);                       // (1.000 × 7 + 500 × 6) / 1.500
        // los grupos por día de corte suman lo mismo que las filas visibles
        $this->assertEquals($t['monto'], collect($r->json('grupos'))->sum('monto'));
        $this->assertSame($t['cajas'], (int) collect($r->json('grupos'))->sum('cajas'));
        $this->assertCount(2, $r->json('grupos'));

        // filtro por día de corte
        $dia = $this->getJson('/api/settlement-payables/board?dia=2026-11-05')->assertOk();
        $this->assertCount(1, $dia->json('filas'));
        $this->assertEquals(2400.00, $dia->json('totales.monto'));
        $this->assertEquals(6.0, $dia->json('totales.precio'));

        // filtro por vendedor, estado y semana
        $this->assertCount(2, $this->getJson('/api/settlement-payables/board?vendedor=' . $this->prod1->id)->json('filas'));
        $this->assertCount(0, $this->getJson('/api/settlement-payables/board?vendedor=' . $this->prod2->id)->json('filas'));
        $this->assertCount(0, $this->getJson('/api/settlement-payables/board?estado=APROBADA')->json('filas'));
        $this->assertCount(0, $this->getJson('/api/settlement-payables/board?semana=47')->json('filas'));
        $this->assertCount(0, $this->getJson('/api/settlement-payables/board?negociacion=CONTRATO')->json('filas'));

        // 'TODAS' trae las devueltas y las aprobadas; los totales siguen siendo la suma de las filas
        $this->estado($ob, 'APROBADA');
        $todas = $this->getJson('/api/settlement-payables/board?estado=TODAS')->assertOk();
        $this->assertCount(2, $todas->json('filas'));
        $this->assertEquals(collect($todas->json('filas'))->sum('monto'), $todas->json('totales.monto'));
        $this->assertCount(1, $this->getJson('/api/settlement-payables/board?estado=APROBADA')->json('filas'));
    }

    public function test_tablero_fila_trae_tarja_y_avisa_si_las_recibidas_son_menores_que_lo_comprado(): void
    {
        [$l, $o] = $this->conOrden('POR_APROBAR');
        $this->tarja($l, 800, 0, 20);
        $this->como($this->jefe);
        $f = $this->getJson('/api/settlement-payables/board')->assertOk()->json('filas.0');
        $this->assertSame(800, $f['tarja_total']);
        $this->assertSame(780, $f['recibidas']);
        $this->assertSame(1000, $f['cajas']);
        $this->assertTrue(collect($f['avisos'])->contains(fn ($a) => str_contains($a, 'RECIBIDAS (780) MENOR QUE LO COMPRADO (1.000)')));
        $this->assertTrue($f['puede_aprobar']);
    }

    public function test_tablero_permisos_y_cuentas_ocultas_para_la_lectura(): void
    {
        $this->conOrden('POR_APROBAR');
        $this->cuenta($this->prod1, false);

        // jefe: ve la cuenta (PENDIENTE) y puede aprobar
        $this->como($this->jefe);
        $r = $this->getJson('/api/settlement-payables/board')->assertOk();
        $this->assertTrue($r->json('ver_cuenta'));
        $this->assertTrue($r->json('puede_aprobar'));
        $this->assertSame('PENDIENTE', $r->json('filas.0.cuenta'));

        // lectura (GERENCIA / FINANZAS): sin cuenta ni botones
        foreach (['GERENCIA', 'FINANZAS'] as $rol) {
            $this->como($this->usuario($rol));
            $l = $this->getJson('/api/settlement-payables/board')->assertOk();
            $this->assertFalse($l->json('ver_cuenta'));
            $this->assertFalse($l->json('puede_aprobar'));
            $this->assertNull($l->json('filas.0.cuenta'));
            $this->assertFalse($l->json('filas.0.puede_aprobar'));
            $this->assertFalse($l->json('filas.0.puede_devolver'));
            $this->assertStringNotContainsString(self::NUMERO_CUENTA, $l->getContent());
            $this->patchJson('/api/settlement-payables/approve', ['items' => [['tipo' => 'anticipo', 'id' => 1]]])->assertForbidden();
            $this->patchJson('/api/settlement-payables/anticipo/1/reject', ['reason' => 'X'])->assertForbidden();
        }

        // LIQUIDACIONES sin la excepción: ve el tablero (Pagos) pero no aprueba
        $this->como($this->usuario('LIQUIDACIONES'));
        $this->getJson('/api/settlement-payables/board')->assertOk();
        $this->patchJson('/api/settlement-payables/approve', ['items' => [['tipo' => 'anticipo', 'id' => 1]]])->assertForbidden();

        // COMERCIAL, BODEGUERO y CONTABILIDAD no ven el tablero
        foreach ([$this->comercial, $this->asistente, $this->usuario('BODEGUERO'), $this->conta] as $u) {
            $this->como($u);
            $this->getJson('/api/settlement-payables/board')->assertForbidden();
        }

        // ADMIN entra a todo
        $this->como($this->admin);
        $this->getJson('/api/settlement-payables/board')->assertOk();
    }

    public function test_aprobar_en_lote_y_la_segunda_vez_dice_cual_ya_cambio(): void
    {
        [$oa, $ob] = $this->dosOrdenes();
        $this->como($this->jefe);
        $items = [['tipo' => 'anticipo', 'id' => $oa->id], ['tipo' => 'anticipo', 'id' => $ob->id]];
        $this->patchJson('/api/settlement-payables/approve', ['items' => $items])->assertOk()->assertJsonPath('aprobadas', 2);

        foreach ([$oa, $ob] as $o) {
            $o = $o->fresh();
            $this->assertSame('APROBADA', $o->approval_status);
            $this->assertSame($this->jefe->id, (int) $o->approved_by);
            $this->assertNotNull($o->approved_at);
        }
        $r = $this->patchJson('/api/settlement-payables/approve', ['items' => [['tipo' => 'anticipo', 'id' => $oa->id]]]);
        $this->assert422($r, "{$oa->payment_order_code}: YA NO ESTÁ POR APROBAR (ESTÁ APROBADA)");
        $this->assertGreaterThanOrEqual(2, AuditLog::where('table_name', 'producer_advances')->where('record_id', $oa->id)->count());
    }

    public function test_aprobar_es_todo_o_nada(): void
    {
        [$oa, $ob] = $this->dosOrdenes();
        $this->estado($ob, 'APROBADA');
        $this->como($this->jefe);
        $r = $this->patchJson('/api/settlement-payables/approve', ['items' => [['tipo' => 'anticipo', 'id' => $oa->id], ['tipo' => 'anticipo', 'id' => $ob->id]]]);
        $this->assert422($r, "{$ob->payment_order_code}: YA NO ESTÁ POR APROBAR");
        $this->assertSame('POR_APROBAR', $oa->fresh()->approval_status);   // la buena tampoco se aprobó
    }

    public function test_nadie_aprueba_lo_que_pidio_salvo_admin(): void
    {
        [$oa] = $this->dosOrdenes();
        $oa->update(['created_by' => $this->jefe->id]);
        $this->como($this->jefe);
        $f = $this->getJson('/api/settlement-payables/board')->assertOk()->json('filas');
        $propia = collect($f)->firstWhere('id', $oa->id);
        $this->assertFalse($propia['puede_aprobar']);

        $r = $this->patchJson('/api/settlement-payables/approve', ['items' => [['tipo' => 'anticipo', 'id' => $oa->id]]]);
        $this->assert422($r, 'NO PUEDES APROBAR UNA ORDEN QUE TÚ PEDISTE');
        $this->assertSame('POR_APROBAR', $oa->fresh()->approval_status);

        $this->como($this->admin);
        $this->patchJson('/api/settlement-payables/approve', ['items' => [['tipo' => 'anticipo', 'id' => $oa->id]]])->assertOk();
        $this->assertSame('APROBADA', $oa->fresh()->approval_status);
    }

    public function test_aprobar_sin_marcar_nada_o_con_tipo_invalido_da_422(): void
    {
        $this->como($this->jefe);
        $this->assert422($this->patchJson('/api/settlement-payables/approve', ['items' => []]), 'MARCA AL MENOS UNA ORDEN PARA APROBAR');
        $this->patchJson('/api/settlement-payables/approve', ['items' => [['tipo' => 'cuota', 'id' => 1]]])->assertStatus(422);
        $r = $this->patchJson('/api/settlement-payables/approve', ['items' => [['tipo' => 'anticipo', 'id' => 999999999]]]);
        $this->assert422($r, 'NO EXISTE');
    }

    public function test_devolver_pide_motivo_avisa_a_quien_la_pidio_y_no_devuelve_lo_que_salio_al_banco(): void
    {
        [$oa, $ob] = $this->dosOrdenes();
        $this->como($this->jefe);
        $this->assert422($this->patchJson("/api/settlement-payables/anticipo/{$oa->id}/reject", []), 'ESCRIBE EL MOTIVO DEL RECHAZO');
        $this->assertSame('POR_APROBAR', $oa->fresh()->approval_status);

        $this->patchJson("/api/settlement-payables/anticipo/{$oa->id}/reject", ['reason' => 'precio raro'])->assertOk()->assertJsonPath('estado', 'DEVUELTA');
        $oa = $oa->fresh();
        $this->assertSame('RECHAZADA', $oa->approval_status);
        $this->assertSame('PRECIO RARO', $oa->rejection_reason);
        $aviso = UserNotification::where('user_id', $this->asistente->id)->where('title', 'ANTICIPO 80 % DEVUELTO')->first();
        $this->assertNotNull($aviso);
        $this->assertStringContainsString('PRECIO RARO', $aviso->body);

        // aprobada sin lote → también se devuelve; en banco o pagada → 422
        $this->estado($ob, 'APROBADA');
        $this->patchJson("/api/settlement-payables/anticipo/{$ob->id}/reject", ['reason' => 'otra'])->assertOk();
        foreach (['EN_BANCO', 'PAGADA'] as $e) {
            $this->estado($ob->fresh(), $e);
            $this->assert422($this->patchJson("/api/settlement-payables/anticipo/{$ob->id}/reject", ['reason' => 'tarde']), 'YA SALIÓ AL BANCO');
            $this->assertSame($e, $ob->fresh()->payment_status);
        }
        $this->patchJson("/api/settlement-payables/cuota/{$ob->id}/reject", ['reason' => 'X'])->assertNotFound(); // solo existe el tipo 'anticipo'
    }

    // ═════════════════════════ Cuentas por pagar (7.7 casos 8, 9, 11, 13; 13.C.1 punto 6) ═════════════════════════

    /** Orden APROBADA del vendedor indicado (compra propia en la semana). */
    private function aprobadaDe(ThirdParty $t, Farm $f, int $cajas = 1000): ProducerAdvance
    {
        $this->como($this->comercial);
        $existentes = collect($this->lineas())->map(fn ($x) => $this->compraDeLinea($x))->all();
        $this->guardar(array_merge($existentes, [$this->compra(['third_party_id' => $t->id, 'farm_id' => $f->id, 'boxes' => $cajas])]))->assertOk();
        $nueva = collect($this->lineas())->last();
        $nueva->update(['sent_to_warehouse_at' => now()]);
        $this->pagar($nueva)->assertStatus(201);

        return $this->estado($this->orden($nueva), 'APROBADA');
    }

    private function compraDeLinea(ProducerQuotaLine $x): array
    {
        return $this->compra(['id' => $x->id, 'third_party_id' => $x->quota->third_party_id, 'farm_id' => $x->farm_id, 'boxes' => (int) $x->quantity_cajas]);
    }

    private function generarLote(array $ids, ?User $quien = null): TestResponse
    {
        $this->como($quien ?? $this->conta);

        return $this->postJson('/api/accounting/payables/batches', ['ids' => $ids]);
    }

    public function test_cuentas_por_pagar_lista_solo_las_aprobadas_con_la_cuenta_enmascarada(): void
    {
        [$l, $o] = $this->conOrden('POR_APROBAR');
        $this->cuenta($this->prod1, false);

        $this->como($this->conta);
        $this->assertSame([], $this->getJson('/api/accounting/payables')->assertOk()->json());   // POR APROBAR no se paga

        $this->estado($o, 'APROBADA');
        $r = $this->getJson('/api/accounting/payables')->assertOk();
        $f = $r->json('0');
        $this->assertSame($o->id, $f['id']);
        $this->assertSame('****4321', $f['cuenta']);
        $this->assertSame('PENDIENTE', $f['estado_cuenta']);
        $this->assertEquals(5600, $f['monto']);
        $this->assertSame(45, $f['semana_pago']);
        $this->assertTrue(collect($f['problemas'])->contains(fn ($p) => str_contains($p, 'CUENTA DEL VENDEDOR ESTÁ PENDIENTE')));
        $this->assertStringNotContainsString(self::NUMERO_CUENTA, $r->getContent());

        // filtros por semana y año de pago
        $this->assertCount(1, $this->getJson('/api/accounting/payables?anio=2026&semana=45')->json());
        $this->assertCount(0, $this->getJson('/api/accounting/payables?semana=46')->json());

        // preview: no genera nada
        $p = $this->postJson('/api/accounting/payables/preview', ['ids' => [$o->id]])->assertOk();
        $this->assertEquals(5600, $p->json('total'));
        $this->assertSame(0, BankPaymentBatch::count());
        $this->assertStringNotContainsString(self::NUMERO_CUENTA, $p->getContent());
    }

    public function test_con_la_cuenta_pendiente_o_sin_cuenta_la_orden_no_entra_al_txt(): void
    {
        [$l, $o] = $this->conOrden('APROBADA');

        // sin cuenta
        $r = $this->generarLote([$o->id]);
        $this->assert422($r, 'SIN CUENTA BANCARIA');
        // cuenta PENDIENTE
        $c = $this->cuenta($this->prod1, false);
        $this->assert422($this->generarLote([$o->id]), 'LA CUENTA DEL VENDEDOR ESTÁ PENDIENTE');
        // banco sin código
        $c->update(['verified_at' => now(), 'verified_by' => $this->conta->id]);
        $this->banco->update(['code' => null]);
        $this->assert422($this->generarLote([$o->id]), 'FALTA EL CÓDIGO DEL BANCO');

        $this->assertSame(0, BankPaymentBatch::count());
        $this->assertNull($o->fresh()->payment_status);
    }

    public function test_orden_no_aprobada_no_se_puede_meter_en_un_lote(): void
    {
        [$l, $o] = $this->conOrden('POR_APROBAR');
        $this->cuenta($this->prod1);
        $this->assert422($this->generarLote([$o->id]), 'NO ESTÁ APROBADA');
        $this->assertSame(0, BankPaymentBatch::count());
    }

    public function test_generar_lote_congela_la_cuenta_y_arma_una_linea_por_orden_en_el_txt(): void
    {
        $oa = $this->aprobadaDe($this->prod1, $this->finca1, 1000);
        $ob = $this->aprobadaDe($this->prod2, $this->finca2, 500);
        $ca = $this->cuenta($this->prod1);
        $this->cuenta($this->prod2);

        $r = $this->generarLote([$oa->id, $ob->id])->assertStatus(201);
        $lote = BankPaymentBatch::findOrFail($r->json('id'));
        $this->assertSame('GENERADO', $lote->status);
        $this->assertSame(2, (int) $lote->payments_count);
        $this->assertEquals(8400.00, (float) $lote->total);               // 5.600 + 2.800 (500 × 7,00 × 0,80)
        $this->assertSame($this->conta->id, (int) $lote->created_by);
        $this->assertSame(2, $lote->items()->where('source_type', 'ANTICIPO')->where('status', 'EN_BANCO')->count());

        foreach ([$oa, $ob] as $o) {
            $o = $o->fresh();
            $this->assertSame('EN_BANCO', $o->payment_status);
            $this->assertSame('LOTE_BANCO', $o->payment_method);
        }

        // la línea guarda una copia de la cuenta (cifrada en la base)
        $item = $lote->items()->where('producer_advance_id', $oa->id)->firstOrFail();
        $this->assertSame(self::NUMERO_CUENTA, $item->account_number);
        $this->assertNotSame(self::NUMERO_CUENTA, DB::table('bank_payment_batch_items')->where('id', $item->id)->value('account_number'));
        $this->assertStringStartsWith('ANT80 ' . $oa->payment_order_code, $item->reference);
        $this->assertEquals(5600.00, (float) $item->amount);
        $this->assertSame($this->prod1->id, (int) $item->payee_third_party_id);

        // una línea por orden en el TXT
        $txt = $this->get("/api/accounting/payables/batches/{$lote->id}/file")->assertOk()->getContent();
        $lineas = array_values(array_filter(preg_split('/\r?\n/', $txt)));
        $this->assertCount(2, $lineas);
        $cols = explode("\t", $lineas[0]);
        $this->assertCount(12, $cols);
        $this->assertSame('PA', $cols[0]);
        $this->assertSame('0000000560000', $cols[3]);                      // $ 5.600,00 en centavos, 13 dígitos
        $this->assertStringContainsString('ANT80', $cols[7]);

        // si la cuenta cambia después, el lote conserva la copia congelada y el TXT sale igual
        $ca->update(['account_number' => '3300111122223333']);
        $this->assertNull($ca->fresh()->verified_at);                      // vuelve a PENDIENTE
        $this->assertSame(self::NUMERO_CUENTA, $item->fresh()->account_number);
        $this->assertSame($txt, $this->get("/api/accounting/payables/batches/{$lote->id}/file")->getContent());

        // la lista de lotes enmascara la cuenta
        $lista = $this->getJson('/api/accounting/payables/batches')->assertOk();
        $this->assertSame('****4321', $lista->json('0.items.0.cuenta'));
        $this->assertStringNotContainsString(self::NUMERO_CUENTA, $lista->getContent());
        $this->assertStringNotContainsString('3300111122223333', $lista->getContent());

        // la misma orden no entra en otro lote (ya está EN BANCO)
        $this->assert422($this->generarLote([$oa->id]), 'YA ESTÁ EN OTRO LOTE DEL BANCO');
        $this->assertSame(1, BankPaymentBatch::count());
    }

    public function test_la_base_impide_la_misma_orden_en_dos_lotes_vivos_y_pagar_sin_aprobar(): void
    {
        $o = $this->aprobadaDe($this->prod1, $this->finca1);
        $this->cuenta($this->prod1);
        $lote = BankPaymentBatch::findOrFail($this->generarLote([$o->id])->assertStatus(201)->json('id'));
        $item = $lote->items()->firstOrFail();

        // doble pago: otra línea viva de la misma orden → índice único
        $otro = BankPaymentBatch::create(['code' => 'PAG-9999-' . random_int(1000, 9999), 'date' => '2026-11-19', 'payments_count' => 1, 'total' => 1,
            'file_name' => 'X.txt', 'content' => '', 'status' => 'GENERADO', 'created_by' => $this->conta->id]);
        try {
            DB::transaction(fn () => $otro->items()->create($item->only(['source_type', 'producer_advance_id', 'payee_third_party_id', 'amount',
                'bank_id', 'account_type', 'account_number', 'holder_id_type', 'holder_id', 'holder_name', 'reference']) + ['status' => 'EN_BANCO']));
            $this->fail('La base debió rechazar la misma orden en dos lotes vivos.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('bpbi_producer_advance_id_vivo_unique', $e->getMessage());
        }

        // pagar una orden sin aprobar → CHECK
        $sinAprobar = $this->estado($this->aprobadaDe($this->prod2, $this->finca2), 'POR_APROBAR');
        try {
            DB::transaction(fn () => $sinAprobar->update(['payment_status' => 'EN_BANCO']));
            $this->fail('La base debió rechazar pagar una orden POR APROBAR.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('pa_pago_aprobado_check', $e->getMessage());
        }
    }

    public function test_anular_el_lote_devuelve_las_ordenes_a_por_pagar_y_pueden_ir_a_otro_lote(): void
    {
        $o = $this->aprobadaDe($this->prod1, $this->finca1);
        $this->cuenta($this->prod1);
        $lote = BankPaymentBatch::findOrFail($this->generarLote([$o->id])->assertStatus(201)->json('id'));
        $this->como($this->conta);

        $this->assert422($this->patchJson("/api/accounting/payables/batches/{$lote->id}/cancel", []), 'ESCRIBE EL MOTIVO');
        $this->assertSame('GENERADO', $lote->fresh()->status);

        $this->patchJson("/api/accounting/payables/batches/{$lote->id}/cancel", ['cancellation_reason' => 'el banco lo rechazó'])->assertOk();
        $this->assertSame('ANULADO', $lote->fresh()->status);
        $this->assertSame('EL BANCO LO RECHAZÓ', $lote->fresh()->cancellation_reason);
        $this->assertSame('ANULADO', $lote->items()->first()->status);
        $o = $o->fresh();
        $this->assertNull($o->payment_status);
        $this->assertNull($o->payment_method);
        $this->assertSame('APROBADA', $o->approval_status);
        $this->assertCount(1, $this->getJson('/api/accounting/payables')->json());

        // anular dos veces → 422; y la orden entra en otro lote
        $this->assert422($this->patchJson("/api/accounting/payables/batches/{$lote->id}/cancel", ['cancellation_reason' => 'otra vez']), 'NO SE PUEDE ANULAR');
        $this->generarLote([$o->id])->assertStatus(201);
    }

    public function test_pagado_con_fallidas_deja_pagado_parcial_y_la_fallida_vuelve_a_por_pagar(): void
    {
        $oa = $this->aprobadaDe($this->prod1, $this->finca1, 1000);
        $ob = $this->aprobadaDe($this->prod2, $this->finca2, 500);
        $this->cuenta($this->prod1);
        $this->cuenta($this->prod2);
        $lote = BankPaymentBatch::findOrFail($this->generarLote([$oa->id, $ob->id])->assertStatus(201)->json('id'));
        $itemA = $lote->items()->where('producer_advance_id', $oa->id)->firstOrFail();
        $itemB = $lote->items()->where('producer_advance_id', $ob->id)->firstOrFail();
        $url = "/api/accounting/payables/batches/{$lote->id}/paid";
        $this->como($this->conta);

        // validaciones
        $this->patchJson($url, ['fallidas' => []])->assertStatus(422);                                          // falta la fecha
        $this->assert422($this->patchJson($url, ['paid_at' => '2026-11-18', 'fallidas' => [['item_id' => $itemA->id]]]), 'ESCRIBE POR QUÉ EL BANCO RECHAZÓ LA LÍNEA');
        $this->assert422($this->patchJson($url, ['paid_at' => '2026-11-18', 'fallidas' => [['item_id' => 999999999, 'motivo' => 'X']]]), 'UNA DE LAS LÍNEAS FALLIDAS NO ES DE ESTE LOTE');
        $this->assert422($this->patchJson($url, ['paid_at' => '2026-11-18', 'fallidas' => [
            ['item_id' => $itemA->id, 'motivo' => 'X'], ['item_id' => $itemB->id, 'motivo' => 'Y']]]), 'ANULA EL LOTE');
        $this->assertSame('GENERADO', $lote->fresh()->status);

        // pagado en parte: B falló
        $r = $this->patchJson($url, ['paid_at' => '2026-11-18', 'bank_reference' => 'ref-77', 'fallidas' => [['item_id' => $itemB->id, 'motivo' => 'cuenta cerrada']]])->assertOk();
        $this->assertSame('PAGADO_PARCIAL', $r->json('status'));
        $this->assertSame('PAGADO_PARCIAL', $lote->fresh()->status);
        $this->assertNotNull($lote->fresh()->paid_by);

        $this->assertSame('PAGADO', $itemA->fresh()->status);
        $this->assertSame('REF-77', $itemA->fresh()->bank_reference);
        $this->assertSame('FALLIDO', $itemB->fresh()->status);
        $this->assertSame('CUENTA CERRADA', $itemB->fresh()->failure_reason);

        $oa = $oa->fresh();
        $this->assertSame('PAGADA', $oa->payment_status);
        $this->assertSame('2026-11-18', substr((string) $oa->paid_at, 0, 10));
        $this->assertSame('REF-77', $oa->payment_reference);
        $this->assertSame($this->conta->id, (int) $oa->paid_by);
        $this->assertSame('FALLIDA', $ob->fresh()->payment_status);
        $this->assertSame('APROBADA', $ob->fresh()->approval_status);

        // la fallida vuelve a por pagar y puede ir a otro lote; la pagada ya no sale
        $porPagar = $this->getJson('/api/accounting/payables')->assertOk()->json();
        $this->assertCount(1, $porPagar);
        $this->assertSame($ob->id, $porPagar[0]['id']);
        $this->assertTrue($porPagar[0]['fallida']);
        $this->generarLote([$ob->id])->assertStatus(201);
        $this->assertSame('EN_BANCO', $ob->fresh()->payment_status);

        // el lote ya cerrado no se vuelve a marcar ni se anula
        $this->assert422($this->patchJson($url, ['paid_at' => '2026-11-19', 'fallidas' => []]), 'EL LOTE ESTÁ PAGADO_PARCIAL');
        $this->assert422($this->patchJson("/api/accounting/payables/batches/{$lote->id}/cancel", ['cancellation_reason' => 'tarde']), 'NO SE PUEDE ANULAR');
    }

    public function test_pagado_completo_deja_el_lote_pagado_y_el_80_listo_para_descontar(): void
    {
        [$l, $o] = $this->conOrden('APROBADA');
        $this->cuenta($this->prod1);
        $lote = BankPaymentBatch::findOrFail($this->generarLote([$o->id])->assertStatus(201)->json('id'));
        $this->patchJson("/api/accounting/payables/batches/{$lote->id}/paid", ['paid_at' => '2026-11-18', 'fallidas' => []])->assertOk()->assertJsonPath('status', 'PAGADO');
        $this->assertSame('PAGADA', $o->fresh()->payment_status);

        // y la LQ SPOT lo descuenta
        $this->parametros();
        $this->tarja($l, 1000);
        $r = $this->liquidar($l->producer_quota_id)->assertStatus(201);
        $this->assertEquals(5600.00, $r->json('advance_80_amount'));
        $this->assertEquals(1400.00, $r->json('net_amount'));
    }

    public function test_el_pagado_sin_el_arreglo_de_fallidas_da_422(): void
    {
        [$l, $o] = $this->conOrden('APROBADA');
        $this->cuenta($this->prod1);
        $lote = BankPaymentBatch::findOrFail($this->generarLote([$o->id])->assertStatus(201)->json('id'));
        $this->patchJson("/api/accounting/payables/batches/{$lote->id}/paid", ['paid_at' => '2026-11-18'])->assertStatus(422);
        $this->assertSame('GENERADO', $lote->fresh()->status);
    }

    public function test_permisos_pagos_banco_y_confirmar_pagos(): void
    {
        $o = $this->aprobadaDe($this->prod1, $this->finca1);
        $this->cuenta($this->prod1);

        // solo pagos_banco (a CONTABILIDAD se le quita confirmar_pagos): genera y baja el TXT, no confirma
        $soloBanco = $this->usuario('CONTABILIDAD', ['screens_removed' => ['confirmar_pagos']]);
        $this->como($soloBanco);
        $this->getJson('/api/accounting/payables')->assertOk();
        $loteId = $this->postJson('/api/accounting/payables/batches', ['ids' => [$o->id]])->assertStatus(201)->json('id');
        $this->get("/api/accounting/payables/batches/$loteId/file")->assertOk();
        $this->patchJson("/api/accounting/payables/batches/$loteId/paid", ['paid_at' => '2026-11-18', 'fallidas' => []])->assertForbidden();
        $this->assertSame('GENERADO', BankPaymentBatch::find($loteId)->status);

        // solo confirmar_pagos: confirma pero no prepara
        $soloConfirma = $this->usuario('CONTABILIDAD', ['screens_removed' => ['pagos_banco']]);
        $this->como($soloConfirma);
        $this->getJson('/api/accounting/payables')->assertForbidden();
        $this->postJson('/api/accounting/payables/preview', ['ids' => [$o->id]])->assertForbidden();
        $this->postJson('/api/accounting/payables/batches', ['ids' => [$o->id]])->assertForbidden();
        $this->get("/api/accounting/payables/batches/$loteId/file")->assertForbidden();
        $this->patchJson("/api/accounting/payables/batches/$loteId/cancel", ['cancellation_reason' => 'X'])->assertForbidden();
        $this->patchJson("/api/accounting/payables/batches/$loteId/paid", ['paid_at' => '2026-11-18', 'fallidas' => []])->assertOk();

        // los demás tipos base no entran, ni con la pantalla agregada a mano ('solo' => CONTABILIDAD)
        $intrusos = [$this->comercial, $this->asistente, $this->jefe, $this->usuario('GERENCIA'), $this->usuario('FINANZAS'), $this->usuario('BODEGUERO'),
            $this->usuario('LIQUIDACIONES', ['screens_added' => ['pagos_banco', 'confirmar_pagos']])];
        foreach ($intrusos as $u) {
            $this->como($u);
            $this->getJson('/api/accounting/payables')->assertForbidden();
            $this->postJson('/api/accounting/payables/batches', ['ids' => [$o->id]])->assertForbidden();
            $this->patchJson("/api/accounting/payables/batches/$loteId/paid", ['paid_at' => '2026-11-18', 'fallidas' => []])->assertForbidden();
        }

        // ADMIN entra a todo
        $this->como($this->admin);
        $this->getJson('/api/accounting/payables')->assertOk();
    }

    public function test_un_perfil_de_liquidaciones_no_puede_recibir_pagos_banco_ni_un_perfil_de_contabilidad_aprobar(): void
    {
        $this->como($this->admin);
        foreach (['pagos_banco', 'confirmar_pagos'] as $p) {
            $this->postJson('/api/profiles', ['name' => "LIQ $p " . uniqid(), 'base_role' => 'LIQUIDACIONES', 'description' => 'X', 'screens' => ['liquidar', $p]])
                ->assertStatus(422)->assertJsonValidationErrors('screens');
        }
        $this->postJson('/api/profiles', ['name' => 'CONTA PRUEBA ' . uniqid(), 'base_role' => 'CONTABILIDAD', 'description' => 'X', 'screens' => ['aprobar_oc', 'aprobar_pagos_liquidacion']])
            ->assertStatus(422)->assertJsonValidationErrors('screens');
    }

    public function test_las_rutas_de_oc_no_listan_ni_tocan_los_lotes_de_anticipos(): void
    {
        $o = $this->aprobadaDe($this->prod1, $this->finca1);
        $this->cuenta($this->prod1);
        $lote = BankPaymentBatch::findOrFail($this->generarLote([$o->id])->assertStatus(201)->json('id'));

        $this->como($this->conta);
        $this->assertSame([], collect($this->getJson('/api/accounting/bank-batches')->assertOk()->json())->where('id', $lote->id)->values()->all());

        $this->assert422($this->get("/api/accounting/bank-batches/{$lote->id}/file"), 'SE MANEJA EN CUENTAS POR PAGAR');
        $this->assert422($this->patchJson("/api/accounting/bank-batches/{$lote->id}/paid"), 'SE MANEJA EN CUENTAS POR PAGAR');
        $this->assert422($this->patchJson("/api/accounting/bank-batches/{$lote->id}/cancel", ['cancellation_reason' => 'PRUEBA']), 'SE MANEJA EN CUENTAS POR PAGAR');
        $this->assertSame('GENERADO', $lote->fresh()->status);
        $this->assertSame('EN_BANCO', $o->fresh()->payment_status);
    }

    public function test_las_rutas_de_anticipos_no_tocan_un_lote_de_oc(): void
    {
        // lote sin líneas de anticipo = lote de OC (o de otro origen): las rutas de Cuentas por pagar dan 404
        $oc = BankPaymentBatch::create(['code' => 'PAG-9999-' . random_int(1000, 9999), 'date' => '2026-11-19', 'payments_count' => 0, 'total' => 0,
            'file_name' => 'OC.txt', 'content' => '', 'status' => 'GENERADO', 'created_by' => $this->conta->id]);
        $this->como($this->conta);
        $this->get("/api/accounting/payables/batches/{$oc->id}/file")->assertNotFound();
        $this->patchJson("/api/accounting/payables/batches/{$oc->id}/cancel", ['cancellation_reason' => 'PRUEBA'])->assertNotFound();
        $this->patchJson("/api/accounting/payables/batches/{$oc->id}/paid", ['paid_at' => '2026-11-18', 'fallidas' => []])->assertNotFound();
        $this->assertSame('GENERADO', $oc->fresh()->status);
        $this->assertSame(0, BankPaymentBatchItem::where('bank_payment_batch_id', $oc->id)->count());
    }
}
