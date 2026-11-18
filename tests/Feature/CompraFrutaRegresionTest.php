<?php

// 18/11/2026: red de seguridad ANTES de rediseñar Comercial → Compra de fruta (grilla tipo SAP).
// Documenta el guardado que HOY funciona: PUT /api/customer-orders/{id}/purchases, bloqueos, versión, división y permisos.
// Base: siae_test con DatabaseTransactions (nunca RefreshDatabase). Cada prueba arma sus datos.
//
// Nota: el "sobre-pedido con aviso" HOY es solo del frontend; el backend deja guardar más cajas que el pedido (se prueba eso).

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderLine;
use App\Models\Farm;
use App\Models\InventoryMovement;
use App\Models\MovementReason;
use App\Models\Port;
use App\Models\Profile;
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

class CompraFrutaRegresionTest extends TestCase
{
    use DatabaseTransactions;

    private static int $n = 0;

    private string $k;
    private User $comercial;
    private CustomerOrder $pedido;
    private ThirdParty $prod1;
    private ThirdParty $prod2;
    private Farm $finca1;
    private Farm $finca2;
    private Warehouse $bodega;
    private Port $puerto;
    private Customer $cliente;
    private Brand $marca;
    private Shipment $barco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('siae_test', \DB::connection()->getDatabaseName());
        $this->k = ++self::$n . '-' . strtoupper(substr(uniqid(), -6));
        $k = $this->k;

        $this->comercial = $this->usuario('COMERCIAL');
        $bodeguero = $this->usuario('BODEGUERO');
        $this->bodega = Warehouse::create(['name' => "BODEGA R$k", 'code' => "BR$k", 'responsible_user_id' => $bodeguero->id, 'status' => true]);
        $this->puerto = Port::create(['name' => "PUERTO R$k", 'port_type' => 'SALIDA', 'status' => true]);
        $this->prod1 = ThirdParty::create(['name' => "PRODUCTOR UNO R$k", 'type' => 'PRODUCTOR', 'status' => true]);
        $this->prod2 = ThirdParty::create(['name' => "PRODUCTOR DOS R$k", 'type' => 'PRODUCTOR', 'status' => true]);
        $this->finca1 = Farm::create(['third_party_id' => $this->prod1->id, 'name' => "FINCA UNO R$k", 'magap_code' => 'C-0001', 'status' => true]);
        $this->finca2 = Farm::create(['third_party_id' => $this->prod2->id, 'name' => "FINCA DOS R$k", 'magap_code' => 'C-0002', 'status' => true]);
        $ct = ThirdParty::create(['name' => "CLIENTE R$k", 'type' => 'CLIENTE', 'status' => true]);
        $this->cliente = Customer::create(['customer_code' => "CUST-R$k", 'third_party_id' => $ct->id, 'country' => 'RUSIA', 'negotiation_type' => 'CONTRATO', 'status' => true]);
        $this->marca = Brand::create(['name' => "MARCA R$k", 'code' => "MR$k", 'status' => true]);
        $this->cliente->brands()->attach($this->marca->id);

        // Pedido de la WK46 de 2026: 5.000 cajas
        $this->pedido = CustomerOrder::create(['week' => 46, 'year' => 2026, 'expected_boxes' => 5000, 'status' => true]);
        CustomerOrderLine::create(['customer_order_id' => $this->pedido->id, 'customer_id' => $this->cliente->id, 'brand_id' => $this->marca->id,
            'presentation' => 'CONVENCIONAL', 'boxes' => 5000, 'incoterm' => 'FOB', 'cargo_type' => 'CAJA_BAJO_CUBIERTA', 'negotiation_type' => 'CONTRATO']);
        $linea = ShippingLine::create(['name' => "NAVIERA R$k"]);
        $this->barco = Shipment::create(['code' => "SR$k", 'registration_date' => '2026-11-09', 'departure_date' => '2026-11-20', 'vessel_name' => "NAVE R$k",
            'shipping_line_id' => $linea->id, 'week_start' => 46, 'week_end' => 46, 'year' => 2026, 'customer_order_id' => $this->pedido->id, 'status' => true]);

        Sanctum::actingAs($this->comercial);
    }

    private function usuario(string $rol, ?int $perfilId = null): User
    {
        return User::create(['name' => "USUARIO $rol", 'email' => strtolower($rol) . self::$n . uniqid() . '@test.local', 'password' => 'Clave-Test-123',
            'role' => $rol, 'profile_id' => $perfilId, 'status' => true, 'must_change_password' => false, 'password_changed_at' => now()]);
    }

    private function url(string $sufijo = ''): string
    {
        return "/api/customer-orders/{$this->pedido->id}/purchases$sufijo";
    }

    private function version(): string
    {
        return $this->getJson($this->url())->assertOk()->json('version');
    }

    private function compra(array $extra = []): array
    {
        return $extra + ['third_party_id' => $this->prod1->id, 'producer_id' => $this->prod1->id, 'farm_id' => $this->finca1->id, 'boxes' => 1000,
            'official_price' => 7.5, 'real_price' => 7.1, 'negotiation_type' => 'SPOT', 'payment_scheme' => '100',
            'pickup_warehouse_id' => $this->bodega->id, 'delivery_port_id' => $this->puerto->id];
    }

    private function bloque(array $compras): array
    {
        return [[
            'id' => PurchaseBlock::where('customer_order_id', $this->pedido->id)->where('brand_id', $this->marca->id)->value('id'),
            'shipment_id' => $this->barco->id, 'brand_id' => $this->marca->id, 'presentation' => 'CONVENCIONAL', 'customer_id' => $this->cliente->id,
            'cargo_mode' => 'CAJA_BAJO_CUBIERTA', 'target_boxes' => 5000, 'purchases' => $compras,
        ]];
    }

    private function guardar(array $compras, ?string $version = null): TestResponse
    {
        return $this->putJson($this->url(), ['version' => $version ?? $this->version(), 'blocks' => $this->bloque($compras)]);
    }

    private function lineas()
    {
        return ProducerQuotaLine::whereIn('purchase_block_id', PurchaseBlock::where('customer_order_id', $this->pedido->id)->pluck('id'))->orderBy('id')->get();
    }

    /** Guarda una compra y devuelve su línea. */
    private function sembrar(array $extra = []): ProducerQuotaLine
    {
        $this->guardar([$this->compra($extra)])->assertOk();
        return $this->lineas()->first();
    }

    /** La misma compra con su id, lista para reenviar editada. */
    private function comoViene(ProducerQuotaLine $l, array $cambios = []): array
    {
        return $this->compra(['id' => $l->id] + $cambios);
    }

    private function assert422(TestResponse $r, string $fragmento): void
    {
        $r->assertStatus(422);
        $mensajes = collect($r->json('errors') ?? [])->flatten();
        $this->assertNotEmpty($mensajes, '422 sin errors.');
        foreach ($mensajes as $m) {
            $this->assertSame(mb_strtoupper($m), $m, "Mensaje no está en MAYÚSCULAS: $m");
        }
        $this->assertTrue($mensajes->contains(fn ($m) => str_contains($m, $fragmento)), "Falta '$fragmento' en: " . $mensajes->implode(' | '));
    }

    private function retiroDeMateriales(ProducerQuotaLine $l): void
    {
        $motivo = MovementReason::create(['name' => "MOTIVO R{$this->k}", 'type' => 'INGRESO', 'status' => true]);
        InventoryMovement::create(['warehouse_id' => $this->bodega->id, 'movement_reason_id' => $motivo->id, 'type' => 'EGRESO', 'date' => '2026-11-10',
            'status' => true, 'producer_quota_id' => $l->producer_quota_id, 'brand_id' => $this->marca->id]);
    }

    private function tarja(ProducerQuotaLine $l): void
    {
        TallySheet::create(['producer_quota_line_id' => $l->id, 'date' => '2026-11-12', 'boxes_shipped' => 100, 'boxes_fallen' => 0, 'boxes_rejected' => 0]);
    }

    private function perfilTarjador(): Profile
    {
        return Profile::firstOrCreate(['name' => 'TARJADOR'], ['base_role' => 'TARJADOR', 'description' => 'TEST', 'screens' => ['tarjas'], 'status' => true]);
    }

    // ───────────────────────── Guardado feliz ─────────────────────────

    public function test_guarda_bloque_y_compra_con_cupo_del_vendedor(): void
    {
        $l = $this->sembrar();
        $this->assertSame(1000, (int) $l->quantity_cajas);
        $this->assertNull($l->producer_id); // compra directa: el vendedor ya es el productor
        $this->assertSame($this->finca1->id, (int) $l->farm_id);
        $this->assertSame('C-0001', $l->mag_code);
        $this->assertEquals(7.5, (float) $l->official_price);
        $this->assertEquals(7.1, (float) $l->real_price);
        $cupo = ProducerQuota::findOrFail($l->producer_quota_id);
        $this->assertSame($this->prod1->id, (int) $cupo->third_party_id);
        $this->assertSame(46, (int) $cupo->week_number);
        $this->assertEquals(1000, (float) $cupo->total_cupo);

        $get = $this->getJson($this->url())->assertOk();
        $this->assertCount(1, $get->json('blocks'));
        $this->assertSame($l->id, (int) $get->json('blocks.0.purchases.0.id'));
    }

    public function test_dos_compras_al_mismo_vendedor_suman_en_un_solo_cupo(): void
    {
        $this->guardar([$this->compra(), $this->compra(['boxes' => 500])])->assertOk();
        $this->assertSame(1, ProducerQuota::where('third_party_id', $this->prod1->id)->where('week_number', 46)->count());
        $this->assertEquals(1500, (float) ProducerQuota::where('third_party_id', $this->prod1->id)->value('total_cupo'));
    }

    public function test_editar_cajas_de_compra_existente_actualiza_cupo(): void
    {
        $l = $this->sembrar();
        $this->guardar([$this->comoViene($l, ['boxes' => 1500])])->assertOk();
        $this->assertSame(1500, (int) $l->fresh()->quantity_cajas);
        $this->assertEquals(1500, (float) $l->quota->fresh()->total_cupo);
        $this->assertSame(1, $this->lineas()->count());
    }

    public function test_quitar_compra_sin_bloqueos_la_elimina_y_borra_cupo_vacio(): void
    {
        $l = $this->sembrar();
        $this->guardar([])->assertOk();
        $this->assertSame(0, $this->lineas()->count());
        $this->assertNull(ProducerQuota::find($l->producer_quota_id));
    }

    public function test_guardar_deja_bitacora(): void
    {
        $l = $this->sembrar();
        $this->assertTrue(AuditLog::where('table_name', 'producer_quota_lines')->where('record_id', $l->id)->where('action', 'CREAR')->exists());
    }

    // ───────────────────────── Validaciones ─────────────────────────

    public function test_cajas_cero_da_422_en_mayusculas(): void
    {
        $this->assert422($this->guardar([$this->compra(['boxes' => 0])]), 'LAS CAJAS DEBEN SER MAYORES A CERO');
        $this->assertSame(0, $this->lineas()->count());
    }

    public function test_finca_de_otro_productor_da_422(): void
    {
        $this->assert422($this->guardar([$this->compra(['farm_id' => $this->finca2->id])]), 'NO ES DE');
    }

    public function test_compra_nueva_sin_finca_da_422(): void
    {
        $this->assert422($this->guardar([$this->compra(['farm_id' => null])]), 'FALTA LA FINCA');
    }

    public function test_semana_de_compra_es_la_del_pedido_o_la_siguiente(): void
    {
        $this->guardar([$this->compra(['purchase_week' => 47])])->assertOk();
        $this->assertSame(47, (int) $this->lineas()->first()->quota->week_number);
        $this->assert422($this->guardar([$this->compra(['purchase_week' => 48])]), 'LA SEMANA DE COMPRA ES LA DEL PEDIDO');
        $this->assert422($this->guardar([$this->compra(['purchase_week' => 45])]), 'LA SEMANA DE COMPRA ES LA DEL PEDIDO');
    }

    public function test_cambio_de_anio_semana_53_a_la_siguiente_es_semana_1(): void
    {
        // 2026 tiene 53 semanas ISO: la siguiente a la WK53 es la WK1 de 2027
        $this->pedido->update(['week' => 53, 'year' => 2026]);
        $this->barco->update(['week_start' => 53, 'week_end' => 53, 'year' => 2026]);
        $this->guardar([$this->compra(['purchase_week' => 1])])->assertOk();
        $cupo = $this->lineas()->first()->quota;
        $this->assertSame(1, (int) $cupo->week_number);
        $this->assertSame(2027, (int) $cupo->year);
    }

    public function test_packed_se_compra_siempre_spot(): void
    {
        $b = $this->bloque([$this->compra(['negotiation_type' => 'CONTRATO'])]);
        $b[0]['presentation'] = 'PACKED';
        $r = $this->putJson($this->url(), ['version' => $this->version(), 'blocks' => $b]);
        $this->assert422($r, 'LA FRUTA PACKED SE COMPRA SIEMPRE SPOT');
    }

    public function test_pedido_anulado_da_422(): void
    {
        $this->pedido->update(['status' => false]);
        $this->guardar([$this->compra()], 'x')->assertStatus(422);
    }

    // ───────────────────────── Sobre-pedido ─────────────────────────

    public function test_sobre_pedido_no_bloquea_el_guardado_en_backend(): void
    {
        // El pedido es de 5.000 cajas; se compran 8.000. El aviso es solo de pantalla: el backend guarda.
        $this->guardar([$this->compra(['boxes' => 8000])])->assertOk();
        $this->assertSame(8000, (int) $this->lineas()->first()->quantity_cajas);
    }

    // ───────────────────────── Bloqueos ─────────────────────────

    public function test_liquidada_no_se_quita_ni_se_modifica(): void
    {
        $l = $this->sembrar();
        $l->quota->update(['status' => ProducerQuota::LIQUIDADO]);
        $this->assert422($this->guardar([]), 'SU CUPO YA FUE LIQUIDADO');
        $this->assert422($this->guardar([$this->comoViene($l, ['boxes' => 1200])]), 'NO SE PUEDE MODIFICAR LA COMPRA');
        $this->assert422($this->guardar([$this->comoViene($l, ['real_price' => 6.5])]), 'NO SE PUEDE MODIFICAR LA COMPRA');
        $this->assertSame(1000, (int) $l->fresh()->quantity_cajas);
        // lo que no cambia sí pasa
        $this->guardar([$this->comoViene($l)])->assertOk();
    }

    public function test_no_se_agregan_compras_a_vendedor_con_semana_liquidada(): void
    {
        $l = $this->sembrar();
        $l->quota->update(['status' => ProducerQuota::LIQUIDADO]);
        $this->assert422($this->guardar([$this->comoViene($l), $this->compra(['boxes' => 300])]), 'YA TIENE LIQUIDADA LA WK46');
    }

    public function test_con_tarjas_no_se_quita_ni_cambia_el_vendedor(): void
    {
        $l = $this->sembrar();
        $this->tarja($l);
        $this->assert422($this->guardar([]), 'YA TIENE TARJAS REGISTRADAS');
        $this->assert422($this->guardar([$this->comoViene($l, ['third_party_id' => $this->prod2->id, 'producer_id' => $this->prod2->id, 'farm_id' => $this->finca2->id])]),
            'NO SE PUEDE CAMBIAR EL VENDEDOR');
        $this->assertSame(1, $this->lineas()->count());
        // con tarjas todavía se pueden editar las cajas (solo liquidada congela)
        $this->guardar([$this->comoViene($l, ['boxes' => 1100])])->assertOk();
    }

    public function test_con_materiales_retirados_no_se_quita_ni_cambia_la_bodega(): void
    {
        $l = $this->sembrar();
        $this->retiroDeMateriales($l);
        $this->assert422($this->guardar([]), 'YA RETIRÓ MATERIALES DE ESA MARCA');
        $otra = Warehouse::create(['name' => "OTRA R{$this->k}", 'code' => "OR{$this->k}", 'status' => true]);
        $this->assert422($this->guardar([$this->comoViene($l, ['pickup_warehouse_id' => $otra->id])]), 'NO SE PUEDE CAMBIAR LA BODEGA DE RETIRO');
        $this->assertSame($this->bodega->id, (int) $l->fresh()->pickup_warehouse_id);
    }

    public function test_no_se_cambia_la_semana_de_una_compra_bloqueada(): void
    {
        $l = $this->sembrar();
        $this->tarja($l);
        $this->assert422($this->guardar([$this->comoViene($l, ['purchase_week' => 47])]), 'NO SE PUEDE CAMBIAR LA SEMANA DE COMPRA');
    }

    // ───────────────────────── Versión / cambios simultáneos ─────────────────────────

    public function test_version_desactualizada_da_409_y_no_guarda(): void
    {
        $this->guardar([$this->compra()], 'version-vieja')->assertStatus(409)->assertJsonPath('conflict', true);
        $this->assertSame(0, $this->lineas()->count());
    }

    public function test_sin_version_da_422(): void
    {
        $this->putJson($this->url(), ['blocks' => $this->bloque([$this->compra()])])->assertStatus(422)->assertJsonValidationErrors('version');
    }

    public function test_dos_usuarios_el_segundo_con_version_vieja_recibe_409(): void
    {
        $v = $this->version();
        $this->guardar([$this->compra()], $v)->assertOk();
        $this->guardar([$this->compra(['boxes' => 2000])], $v)->assertStatus(409);
        $this->assertSame(1000, (int) $this->lineas()->first()->quantity_cajas);
        $this->assertNotSame($v, $this->version(), 'La versión debe cambiar tras guardar.');
    }

    public function test_enviar_a_bodega_no_cambia_la_version(): void
    {
        $l = $this->sembrar();
        $v = $this->version();
        $this->postJson($this->url('/send'), ['purchase_ids' => [$l->id]])->assertOk();
        $this->assertSame($v, $this->version());
    }

    // ───────────────────────── División de compra ─────────────────────────

    private function filasDivision(): array
    {
        $id = PurchaseBlock::where('customer_order_id', $this->pedido->id)->value('id');
        // compra directa a un productor: solo se divide por finca (mismo productor, otra finca)
        $otra = Farm::create(['third_party_id' => $this->prod1->id, 'name' => "FINCA TRES R{$this->k}", 'magap_code' => 'C-0003', 'status' => true]);
        return [
            ['purchase_block_id' => $id, 'producer_id' => $this->prod1->id, 'farm_id' => $this->finca1->id, 'boxes' => 400],
            ['purchase_block_id' => $id, 'producer_id' => $this->prod1->id, 'farm_id' => $otra->id, 'boxes' => 600],
        ];
    }

    public function test_dividir_compra_en_dos_filas_conserva_el_total(): void
    {
        $l = $this->sembrar();
        $r = $this->postJson($this->url("/{$l->id}/split"), ['version' => $this->version(), 'rows' => $this->filasDivision()]);
        $r->assertOk()->assertJsonPath('divididas', 2)->assertJsonPath('saldo', 0);
        $this->assertEquals(1000, (float) $this->lineas()->sum('quantity_cajas'));
        $this->assertSame(2, $this->lineas()->count());
    }

    public function test_dividir_con_version_vieja_da_409(): void
    {
        $l = $this->sembrar();
        $this->postJson($this->url("/{$l->id}/split"), ['version' => 'vieja', 'rows' => $this->filasDivision()])->assertStatus(409);
        $this->assertSame(1, $this->lineas()->count());
    }

    // ───────────────────────── Permisos ─────────────────────────

    public function test_sin_la_pantalla_compra_fruta_da_403_en_todas_las_rutas(): void
    {
        $l = $this->sembrar();
        $v = $this->version();
        $usuarios = [$this->usuario('BODEGUERO'), $this->usuario('TARJADOR', $this->perfilTarjador()->id),
            $this->usuario('LIQUIDACIONES'), $this->usuario('EXPORTACIONES')];
        foreach ($usuarios as $u) {
            Sanctum::actingAs($u);
            $this->getJson($this->url())->assertStatus(403);
            $this->putJson($this->url(), ['version' => $v, 'blocks' => $this->bloque([])])->assertStatus(403);
            $this->postJson($this->url('/send'), ['purchase_ids' => [$l->id]])->assertStatus(403);
            $this->postJson($this->url("/{$l->id}/split"), ['version' => $v, 'rows' => []])->assertStatus(403);
            $this->postJson($this->url('/farms'), ['third_party_id' => $this->prod1->id, 'name' => 'X'])->assertStatus(403);
        }
        $this->assertSame(1, $this->lineas()->count());
    }

    public function test_comercial_y_admin_entran_y_excepcion_por_persona_abre_la_pantalla(): void
    {
        $this->getJson($this->url())->assertOk();
        Sanctum::actingAs($this->usuario('ADMIN'));
        $this->getJson($this->url())->assertOk();

        $bod = $this->usuario('BODEGUERO');
        Sanctum::actingAs($bod);
        $this->getJson($this->url())->assertStatus(403);
        $bod->forceFill(['screens_added' => ['compra_fruta']])->save();
        $this->getJson($this->url())->assertOk();
    }
}
