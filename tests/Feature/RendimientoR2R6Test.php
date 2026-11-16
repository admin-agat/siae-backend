<?php

// 16/11/2026: pruebas de los arreglos de rendimiento R2–R6. El RESULTADO debe ser idéntico al de antes
// (solo menos consultas). Base: siae_test con DatabaseTransactions (nunca RefreshDatabase).
//
//  R2  MaterialDispatchController::filasPorDespachar  → GET /api/material-dispatch/pending
//  R3  PurchaseAllocationController::update           → bloqueos por tarjas / retiros
//  R4  PurchaseAllocationController::update           → puertos y cupos (un cupo por vendedor + semana)
//  R5  PurchaseAllocationController::cargar           → última bodega por vendedor
//  R6  SettlementController::quotas                   → ?recientes=1
//
// Si se define la variable de entorno R2_SNAPSHOT=<ruta>, la prueba de R2 vuelca la respuesta normalizada
// (sin ids) a ese archivo, para compararla contra la versión anterior del controlador.

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderLine;
use App\Models\Farm;
use App\Models\MaterialRecipe;
use App\Models\MovementReason;
use App\Models\Port;
use App\Models\ProducerQuota;
use App\Models\ProducerQuotaLine;
use App\Models\PurchaseBlock;
use App\Models\Settlement;
use App\Models\Shipment;
use App\Models\ShippingLine;
use App\Models\Supply;
use App\Models\SupplyCategory;
use App\Models\TallySheet;
use App\Models\ThirdParty;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RendimientoR2R6Test extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private CustomerOrder $pedido;
    private ThirdParty $asoc;
    private ThirdParty $prod1;
    private ThirdParty $prod2;
    private Farm $finca1;
    private Farm $finca2;
    private Warehouse $bodega;
    private Warehouse $bodega2;
    private Port $puerto;
    private Customer $cliente;
    /** @var Brand[] */
    private array $marcas = [];
    private Shipment $barco;
    private MovementReason $motivo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('siae_test', DB::connection()->getDatabaseName());
        $this->armar();
        Sanctum::actingAs($this->admin);
    }

    private function armar(): void
    {
        $k = strtoupper(substr(uniqid(), -6));
        $this->admin = User::create(['name' => 'USUARIO ADMIN', 'email' => "admin$k@test.local", 'password' => 'Clave-Test-123',
            'role' => 'ADMIN', 'status' => true, 'must_change_password' => false, 'password_changed_at' => now()]);
        $this->bodega = Warehouse::create(['name' => 'R2-BODEGA 1', 'code' => "R1$k", 'status' => true]);
        $this->bodega2 = Warehouse::create(['name' => 'R2-BODEGA 2', 'code' => "R2$k", 'status' => true]);
        $this->puerto = Port::create(['name' => "PUERTO R$k", 'port_type' => 'SALIDA', 'status' => true]);
        $this->motivo = MovementReason::create(['name' => 'ENTREGA A PRODUCTOR', 'type' => 'EGRESO', 'status' => true]);

        $this->asoc = ThirdParty::create(['name' => "R2-ASOCIACION", 'type' => 'COMERCIALIZADORA', 'status' => true]);
        $this->prod1 = ThirdParty::create(['name' => "R2-PRODUCTOR UNO", 'type' => 'PRODUCTOR', 'status' => true]);
        $this->prod2 = ThirdParty::create(['name' => "R2-PRODUCTOR DOS", 'type' => 'PRODUCTOR', 'status' => true]);
        $this->finca1 = Farm::create(['third_party_id' => $this->prod1->id, 'name' => "R2-FINCA UNO", 'magap_code' => 'C-0001', 'status' => true]);
        $this->finca2 = Farm::create(['third_party_id' => $this->prod2->id, 'name' => "R2-FINCA DOS", 'magap_code' => 'C-0002', 'status' => true]);

        $clienteTercero = ThirdParty::create(['name' => "R2-CLIENTE", 'type' => 'CLIENTE', 'status' => true]);
        $this->cliente = Customer::create(['customer_code' => "CUST-R$k", 'third_party_id' => $clienteTercero->id,
            'country' => 'RUSIA', 'negotiation_type' => 'CONTRATO', 'status' => true]);
        foreach (['A', 'B'] as $i => $letra) {
            $this->marcas[$i] = Brand::create(['name' => "R2-MARCA $letra", 'code' => "R2_M$letra", 'status' => true]);
            $this->cliente->brands()->attach($this->marcas[$i]->id);
        }

        $this->pedido = CustomerOrder::create(['week' => 46, 'year' => 2026, 'expected_boxes' => 20000, 'status' => true]);
        CustomerOrderLine::create([
            'customer_order_id' => $this->pedido->id, 'customer_id' => $this->cliente->id, 'brand_id' => $this->marcas[0]->id,
            'presentation' => 'CONVENCIONAL', 'boxes' => 20000, 'incoterm' => 'FOB', 'cargo_type' => 'CAJA_BAJO_CUBIERTA',
            'negotiation_type' => 'CONTRATO',
        ]);
        $linea = ShippingLine::create(['name' => "NAVIERA R$k"]);
        $this->barco = Shipment::create([
            'code' => "SR$k", 'registration_date' => '2026-11-09', 'departure_date' => '2026-11-20', 'vessel_name' => "NAVE R$k",
            'shipping_line_id' => $linea->id, 'week_start' => 46, 'week_end' => 46, 'year' => 2026,
            'customer_order_id' => $this->pedido->id, 'status' => true,
        ]);
    }

    // ───────────────────────── Ayudas de la compra de fruta ─────────────────────────

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
        return $extra + [
            'third_party_id' => $this->prod1->id, 'farm_id' => $this->finca1->id, 'boxes' => 1000,
            'official_price' => 7.5, 'real_price' => 7.1, 'negotiation_type' => 'SPOT', 'payment_scheme' => '100',
            'pickup_warehouse_id' => $this->bodega->id, 'delivery_port_id' => $this->puerto->id,
        ];
    }

    private function idBloque(int $i): ?int
    {
        return PurchaseBlock::where('customer_order_id', $this->pedido->id)->where('brand_id', $this->marcas[$i]->id)->value('id');
    }

    private function bloques(array $c0, array $c1 = [], array $extraBloque0 = []): array
    {
        $def = [[0, $c0], [1, $c1]];
        return array_map(fn ($d) => ($d[0] === 0 ? $extraBloque0 : []) + [
            'id' => $this->idBloque($d[0]), 'shipment_id' => $this->barco->id, 'brand_id' => $this->marcas[$d[0]]->id,
            'presentation' => 'CONVENCIONAL', 'customer_id' => $this->cliente->id, 'cargo_mode' => 'CAJA_BAJO_CUBIERTA',
            'target_boxes' => 7000, 'purchases' => $d[1],
        ], $def);
    }

    private function guardar(array $c0, array $c1 = [], array $extraBloque0 = []): TestResponse
    {
        return $this->putJson($this->url(), ['version' => $this->version(), 'blocks' => $this->bloques($c0, $c1, $extraBloque0)]);
    }

    /** Guarda y devuelve la línea de la primera compra del bloque 0 con ese vendedor. */
    private function sembrar(array $extra = []): ProducerQuotaLine
    {
        $this->guardar([$this->compra($extra)])->assertOk();
        return ProducerQuotaLine::where('purchase_block_id', $this->idBloque(0))->firstOrFail();
    }

    private function retiro(ProducerQuota $cupo, Brand $marca, Warehouse $bodega, array $lineas, bool $activo = true, ?string $fecha = null, ?string $creado = null): int
    {
        $id = DB::table('inventory_movements')->insertGetId([
            'warehouse_id' => $bodega->id, 'movement_reason_id' => $this->motivo->id, 'type' => 'EGRESO',
            'date' => $fecha ?? '2026-11-10', 'status' => $activo, 'producer_quota_id' => $cupo->id, 'brand_id' => $marca->id,
            'created_by_user_id' => $this->admin->id,
            'created_at' => $creado ?? '2026-11-10 10:00:00', 'updated_at' => $creado ?? '2026-11-10 10:00:00',
        ]);
        foreach ($lineas as $supplyId => $cant) {
            DB::table('inventory_movement_lines')->insert([
                'inventory_movement_id' => $id, 'supply_id' => $supplyId, 'quantity' => $cant,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $id;
    }

    private function assert422(TestResponse $r, string $fragmento): void
    {
        $r->assertStatus(422);
        $mensajes = collect($r->json('errors') ?? [])->flatten();
        $this->assertTrue($mensajes->contains(fn ($m) => str_contains($m, $fragmento)),
            "Falta '$fragmento' en: " . $mensajes->implode(' | '));
    }

    // ───────────────────────── R2: Por despachar ─────────────────────────

    /** Escenario con 2 marcas, 2 bodegas, retiros en ambas, uno anulado, compras con y sin bodega fija. */
    private function escenarioDespacho(): void
    {
        $cat = SupplyCategory::create(['name' => 'R2-CAT', 'status' => true]);
        $s1 = Supply::create(['supply_category_id' => $cat->id, 'code' => 'R2-S1', 'name' => 'R2-INSUMO 1', 'unit' => 'UNIDAD', 'status' => true]);
        $s2 = Supply::create(['supply_category_id' => $cat->id, 'code' => 'R2-S2', 'name' => 'R2-INSUMO 2', 'unit' => 'LIBRAS', 'status' => true]);
        $s3 = Supply::create(['supply_category_id' => $cat->id, 'code' => 'R2-S3', 'name' => 'R2-INSUMO 3', 'unit' => 'UNIDAD', 'status' => true]);
        // Marca A: S1 ×1 y S2 ×0.5; marca B: S1 ×2 y S3 ×1
        foreach ([[0, $s1, 1], [0, $s2, 0.5], [1, $s1, 2], [1, $s3, 1]] as [$m, $s, $ratio]) {
            MaterialRecipe::create(['supply_id' => $s->id, 'base_cupo' => 'MARCA', 'ratio_per_box' => $ratio, 'unit' => $s->unit,
                'status' => true, 'brand' => $this->marcas[$m]->code]);
        }

        $enviada = now()->subDays(5);
        $mk = fn (ProducerQuota $q, int $marca, ?Warehouse $b, int $cajas, bool $enviar = true, $cambio = null) => ProducerQuotaLine::create([
            'producer_quota_id' => $q->id, 'variety' => $this->marcas[$marca]->code, 'brand_id' => $this->marcas[$marca]->id,
            'quantity_cajas' => $cajas, 'customer_id' => $this->cliente->id, 'negotiation_type' => 'SPOT', 'payment_scheme' => '100',
            'pickup_warehouse_id' => $b?->id, 'sent_to_warehouse_at' => $enviar ? $enviada : null, 'sent_to_warehouse_by' => $enviar ? $this->admin->id : null,
            'changed_after_sent_at' => $cambio,
        ]);
        $cupo = fn (ThirdParty $t, int $semana = 46) => ProducerQuota::create(['third_party_id' => $t->id, 'week_number' => $semana, 'year' => 2026,
            'total_cupo' => 0, 'status' => ProducerQuota::ASIGNADO, 'customer_order_id' => $this->pedido->id]);

        // Cupo 1 (prod1): A en bodega 1 (100) y bodega 2 (50) — todas con bodega fija; B sin bodega (40)
        $q1 = $cupo($this->prod1);
        $mk($q1, 0, $this->bodega, 100);
        $mk($q1, 0, $this->bodega2, 50, true, now()->addDay()); // cambió después del último retiro → cambio = true
        $mk($q1, 1, null, 40);
        $mk($q1, 1, $this->bodega, 10, false);                  // sin enviar: no sale en la bandeja ni cuenta como bodega
        // Retiros cupo 1
        $this->retiro($q1, $this->marcas[0], $this->bodega, [$s1->id => 60, $s2->id => 10], true, '2026-11-10', '2026-11-10 10:00:00');
        $this->retiro($q1, $this->marcas[0], $this->bodega, [$s1->id => 40], true, '2026-11-12', '2026-11-12 09:00:00');
        $this->retiro($q1, $this->marcas[0], $this->bodega, [$s1->id => 999], false, '2026-11-13', '2026-11-13 09:00:00'); // ANULADO
        $this->retiro($q1, $this->marcas[0], $this->bodega2, [$s1->id => 20], true, '2026-11-11', '2026-11-11 08:00:00');
        $this->retiro($q1, $this->marcas[1], $this->bodega, [$s1->id => 10], true, '2026-11-09', '2026-11-09 08:00:00');
        $this->retiro($q1, $this->marcas[1], $this->bodega2, [$s1->id => 15, $s3->id => 5], true, '2026-11-14', '2026-11-14 08:00:00');

        // Cupo 2 (prod2): A sin bodega (30) y A en bodega 1 (20) → hay compra sin bodega → cuenta retiros de cualquier bodega
        $q2 = $cupo($this->prod2);
        $mk($q2, 0, null, 30);
        $mk($q2, 0, $this->bodega, 20, true, now()->subDays(10)); // cambio anterior al retiro → cambio = false
        $this->retiro($q2, $this->marcas[0], $this->bodega, [$s1->id => 30], true, '2026-11-12', '2026-11-12 12:00:00');
        $this->retiro($q2, $this->marcas[0], $this->bodega2, [$s1->id => 5, $s2->id => 3], true, '2026-11-15', '2026-11-15 12:00:00');

        // Cupo 3 (comercializadora): sin ningún retiro → PENDIENTE; marca B en bodega 2
        $q3 = $cupo($this->asoc);
        $mk($q3, 1, $this->bodega2, 25);

        // Cupo 4: de otra semana, enviada, sin recetas para su marca ... marca A sin retiros, bodega 1
        $q4 = $cupo($this->prod1, 47);
        $mk($q4, 0, $this->bodega, 8);

        // Cupo 5: LIQUIDADO → no debe salir
        $q5 = $cupo($this->prod2, 45);
        $q5->update(['status' => ProducerQuota::LIQUIDADO]);
        $mk($q5, 0, $this->bodega, 8);
    }

    private function normalizar(array $filas): array
    {
        return collect($filas)->map(function ($f) {
            unset($f['producer_quota_id'], $f['third_party_id'], $f['warehouse_id'], $f['enviado_at'], $f['cambio_at'], $f['enviado_por']);
            return $f;
        })->sortBy(fn ($f) => $f['producer_name'] . '|' . $f['week'] . '|' . $f['brand_code'] . '|' . ($f['warehouse_name'] ?? ''))->values()->all();
    }

    public function test_r2_por_despachar_resultado_campo_por_campo(): void
    {
        $this->escenarioDespacho();

        DB::enableQueryLog();
        $r = $this->getJson('/api/material-dispatch/pending')->assertOk();
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $filas = collect($r->json())->keyBy(fn ($f) => $f['producer_name'] . '|' . $f['week'] . '|' . $f['brand_code'] . '|' . ($f['warehouse_name'] ?? '-'));
        // Cupo 5 (liquidado) y la compra sin enviar no salen. 6 filas: q1 A-b1, q1 A-b2, q1 B-sin bodega, q2 A-sin bodega, q2 A-b1, q3, q4
        $this->assertCount(7, $filas, $filas->keys()->implode(' ; '));

        // q1 marca A bodega 1: formula S1=100 retirado 100 (60+40; el anulado NO cuenta), S2=50 (LIBRAS) retirado 10 → PARCIAL
        $f = $filas['R2-PRODUCTOR UNO|46|R2_MA|R2-BODEGA 1'];
        $this->assertSame('PARCIAL', $f['estado']);
        $this->assertSame(2, $f['materiales_total']);
        $this->assertSame(1, $f['materiales_faltan']);
        $this->assertSame((int) round((1.0 + 10 / 50) / 2 * 100), $f['avance']); // 60
        $this->assertSame(2, $f['retiros']);                                      // el anulado no se cuenta
        $this->assertSame('2026-11-12', substr($f['ultimo_retiro'], 0, 10));
        $this->assertFalse($f['cambio']);

        // q1 marca A bodega 2: formula S1=50, S2=25; retirado S1=20 solo de bodega 2 → PARCIAL, 1 retiro, cambio = true (cambió mañana > último retiro)
        $f = $filas['R2-PRODUCTOR UNO|46|R2_MA|R2-BODEGA 2'];
        $this->assertSame('PARCIAL', $f['estado']);
        $this->assertSame(2, $f['materiales_faltan']);
        $this->assertSame((int) round((20 / 50 + 0) / 2 * 100), $f['avance']);    // 20
        $this->assertSame(1, $f['retiros']);
        $this->assertSame('2026-11-11', substr($f['ultimo_retiro'], 0, 10));
        $this->assertTrue($f['cambio']);

        // q1 marca B sin bodega (cuenta retiros de CUALQUIER bodega): S1 = 40×2=80, retirado 10+15=25; S3=40 retirado 5 → 2 retiros
        $f = $filas['R2-PRODUCTOR UNO|46|R2_MB|-'];
        $this->assertSame('PARCIAL', $f['estado']);
        $this->assertSame(2, $f['retiros']);
        $this->assertSame(2, $f['materiales_faltan']);
        $this->assertSame((int) round((25 / 80 + 5 / 40) / 2 * 100), $f['avance']);
        $this->assertSame('2026-11-14', substr($f['ultimo_retiro'], 0, 10));

        // q2: hay una compra de la marca sin bodega → las DOS filas cuentan los retiros de todas las bodegas
        foreach (['R2-PRODUCTOR DOS|46|R2_MA|-' => 30, 'R2-PRODUCTOR DOS|46|R2_MA|R2-BODEGA 1' => 20] as $llave => $cajas) {
            $f = $filas[$llave];
            $this->assertSame($cajas, $f['cajas']);
            $this->assertSame(2, $f['retiros'], $llave);
            $this->assertSame('2026-11-15', substr($f['ultimo_retiro'], 0, 10), $llave);
            $this->assertSame('PARCIAL', $f['estado'], $llave);
        }
        $this->assertFalse($filas['R2-PRODUCTOR DOS|46|R2_MA|R2-BODEGA 1']['cambio']);

        // q3 sin retiros: PENDIENTE, avance 0, sin ultimo_retiro
        $f = $filas['R2-ASOCIACION|46|R2_MB|R2-BODEGA 2'];
        $this->assertSame('PENDIENTE', $f['estado']);
        $this->assertSame(0, $f['avance']);
        $this->assertSame(0, $f['retiros']);
        $this->assertNull($f['ultimo_retiro']);
        $this->assertFalse($f['cambio']);

        // Resultado idéntico a la lógica anterior (una consulta por cupo × marca × bodega), reimplementada aquí
        $this->assertEquals($this->esperadoLogicaVieja(), $this->camposClave($filas->values()->all()));

        file_put_contents(sys_get_temp_dir() . '/r2_consultas.txt', "R2 consultas pending: $consultas\n", FILE_APPEND);
        if ($ruta = getenv('R2_SNAPSHOT')) {
            file_put_contents($ruta, json_encode($this->normalizar($r->json()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            file_put_contents($ruta . '.consultas', (string) $consultas);
        }
    }

    public function test_r2_contador_coincide_con_pending(): void
    {
        $this->escenarioDespacho();
        $filas = collect($this->getJson('/api/material-dispatch/pending')->assertOk()->json());
        $esperado = $filas->whereIn('estado', ['PENDIENTE', 'PARCIAL'])->count();
        $this->getJson('/api/material-dispatch/pending/count')->assertOk()->assertJson(['count' => $esperado]);
    }

    public function test_r2_bodeguero_solo_ve_su_bodega(): void
    {
        $this->escenarioDespacho();
        $bod = User::create(['name' => 'BODEGUERO R2', 'email' => 'bod' . uniqid() . '@test.local', 'password' => 'Clave-Test-123',
            'role' => 'BODEGUERO', 'status' => true, 'must_change_password' => false, 'password_changed_at' => now()]);
        $this->bodega2->update(['responsible_user_id' => $bod->id]);
        Sanctum::actingAs($bod);
        $filas = $this->getJson('/api/material-dispatch/pending');
        if ($filas->status() === 403) {
            $this->markTestSkipped('BODEGUERO sin pantalla despacho en este perfil.');
        }
        $filas->assertOk();
        foreach ($filas->json() as $f) {
            // Las compras sin bodega fija (warehouse_id null) salen para cualquier bodeguero (comportamiento de antes)
            $this->assertTrue($f['warehouse_id'] === null || (int) $f['warehouse_id'] === $this->bodega2->id);
        }
        $this->assertNotEmpty(collect($filas->json())->where('warehouse_id', $this->bodega2->id));
        $this->assertEmpty(collect($filas->json())->where('warehouse_id', $this->bodega->id));
    }

    /** Lógica ANTERIOR (yaDespachado + 3 consultas por cupo × marca × bodega) para cada fila devuelta. */
    private function esperadoLogicaVieja(): array
    {
        $recetas = MaterialRecipe::with('supply')->where('status', true)->get()->groupBy('brand');
        $cupos = ProducerQuota::with('lines')->whereNotNull('customer_order_id')
            ->whereHas('lines', fn ($q) => $q->whereNotNull('customer_id')->whereNotNull('sent_to_warehouse_at'))
            ->where('status', '!=', ProducerQuota::LIQUIDADO)->get();
        $out = [];
        foreach ($cupos as $cupo) {
            $enviadas = $cupo->lines->whereNotNull('sent_to_warehouse_at');
            foreach ($enviadas->groupBy(fn ($l) => $l->variety . '|' . $l->pickup_warehouse_id) as $lineas) {
                $codigo = $lineas->first()->variety;
                $bodegaId = $lineas->first()->pickup_warehouse_id;
                $marca = Brand::where('code', $codigo)->first();
                $cajas = (int) $lineas->sum('quantity_cajas');
                $sinBodega = $cupo->lines->where('variety', $codigo)->contains(fn ($l) => !$l->pickup_warehouse_id);
                $desc = $sinBodega ? null : $bodegaId;

                $ya = DB::table('inventory_movement_lines as l')->join('inventory_movements as m', 'm.id', '=', 'l.inventory_movement_id')
                    ->where('m.producer_quota_id', $cupo->id)->where('m.brand_id', $marca->id)
                    ->when($desc, fn ($q) => $q->where('m.warehouse_id', $desc))
                    ->where('m.type', 'EGRESO')->where('m.status', true)->groupBy('l.supply_id')
                    ->selectRaw('l.supply_id, SUM(l.quantity) as cantidad')->pluck('cantidad', 'l.supply_id')->map(fn ($c) => (float) $c)->all();
                $items = collect($recetas[$codigo] ?? [])->keyBy('supply_id')->map(function ($r) use ($cajas, $ya) {
                    $f = $r->supply->unit === 'LIBRAS' ? round($cajas * (float) $r->ratio_per_box, 2) : (float) ceil(round($cajas * (float) $r->ratio_per_box, 6));
                    $ret = $ya[$r->supply_id] ?? 0;
                    $falta = $r->supply->unit === 'LIBRAS' ? round($f - $ret, 2) : (float) ceil(round($f - $ret, 6));
                    return ['formula' => $f, 'falta' => max(0, $falta), 'retirado' => $ret];
                })->filter(fn ($i) => $i['formula'] > 0);
                $retiros = DB::table('inventory_movements')->where('producer_quota_id', $cupo->id)->where('brand_id', $marca->id)
                    ->when($desc, fn ($q) => $q->where('warehouse_id', $desc))->where('type', 'EGRESO')->where('status', true);
                $cambioAt = $lineas->max('changed_after_sent_at');
                $ultimo = (clone $retiros)->max('created_at');
                $cambio = $cambioAt && (!$ultimo || $cambioAt->gt(\Carbon\Carbon::parse($ultimo)));
                $faltan = $items->filter(fn ($i) => $i['falta'] > 0)->count();
                $estado = match (true) {
                    $items->isEmpty() => 'SIN_RECETA', $items->sum('retirado') <= 0 => 'PENDIENTE', $faltan > 0 => 'PARCIAL', default => 'COMPLETO',
                };
                $out[] = [
                    'llave' => $cupo->id . '|' . $codigo . '|' . ($bodegaId ?? '-'),
                    'estado' => $estado,
                    'avance' => $items->isEmpty() ? 0 : (int) round($items->avg(fn ($i) => min(1, $i['retirado'] / $i['formula'])) * 100),
                    'materiales_faltan' => $faltan,
                    'retiros' => (int) (clone $retiros)->count(),
                    'ultimo_retiro' => (clone $retiros)->max('date') ? substr((string) (clone $retiros)->max('date'), 0, 10) : null,
                    'cambio' => (bool) $cambio,
                ];
            }
        }
        return collect($out)->sortBy('llave')->values()->all();
    }

    private function camposClave(array $filas): array
    {
        return collect($filas)->map(fn ($f) => [
            'llave' => $f['producer_quota_id'] . '|' . $f['brand_code'] . '|' . ($f['warehouse_id'] ?? '-'),
            'estado' => $f['estado'], 'avance' => $f['avance'], 'materiales_faltan' => $f['materiales_faltan'],
            'retiros' => $f['retiros'], 'ultimo_retiro' => $f['ultimo_retiro'] ? substr($f['ultimo_retiro'], 0, 10) : null,
            'cambio' => $f['cambio'],
        ])->sortBy('llave')->values()->all();
    }

    // ───────────────────────── R3: bloqueos de la compra ─────────────────────────

    public function test_r3_compra_con_tarjas_no_se_quita_ni_cambia(): void
    {
        $l = $this->sembrar();
        TallySheet::create(['producer_quota_line_id' => $l->id, 'shipment_id' => $this->barco->id, 'date' => '2026-11-10',
            'boxes_shipped' => 100, 'boxes_fallen' => 0, 'boxes_rejected' => 0]);

        $this->assert422($this->guardar([]), 'YA TIENE TARJAS REGISTRADAS');
        $this->assert422($this->guardar([$this->compra(['id' => $l->id, 'third_party_id' => $this->prod2->id, 'farm_id' => $this->finca2->id])]),
            'NO SE PUEDE CAMBIAR EL VENDEDOR');
        $this->assertSame(1, ProducerQuotaLine::where('purchase_block_id', $this->idBloque(0))->count());
        // Editar algo permitido (cajas) sigue funcionando
        $this->guardar([$this->compra(['id' => $l->id, 'boxes' => 1200])])->assertOk();
        $this->assertSame(1200, (int) $l->fresh()->quantity_cajas);
    }

    public function test_r3_compra_con_retiro_y_bodega_fija_se_bloquea_solo_en_su_bodega(): void
    {
        $l = $this->sembrar(); // bodega fija 1
        $cupo = $l->quota;
        // Retiro en la OTRA bodega: no bloquea
        $this->retiro($cupo, $this->marcas[0], $this->bodega2, []);
        $this->guardar([])->assertOk();

        $l = $this->sembrar();
        $cupo = $l->quota;
        $this->retiro($cupo, $this->marcas[0], $this->bodega, [], false); // anulado: no bloquea
        $this->guardar([$this->compra(['id' => $l->id])])->assertOk();
        $this->retiro($cupo, $this->marcas[0], $this->bodega, []);        // activo en SU bodega: bloquea
        $this->assert422($this->guardar([]), 'YA RETIRÓ MATERIALES DE ESA MARCA');
        $this->assert422($this->guardar([$this->compra(['id' => $l->id, 'pickup_warehouse_id' => $this->bodega2->id])]),
            'NO SE PUEDE CAMBIAR LA BODEGA DE RETIRO');
    }

    public function test_r3_compra_sin_bodega_se_bloquea_con_retiro_en_cualquier_bodega(): void
    {
        $l = $this->sembrar(['pickup_warehouse_id' => null]);
        $this->assertNull($l->pickup_warehouse_id);
        $this->guardar([$this->compra(['id' => $l->id, 'pickup_warehouse_id' => null])])->assertOk();
        $this->retiro($l->quota, $this->marcas[0], $this->bodega2, []);
        $this->assert422($this->guardar([]), 'YA RETIRÓ MATERIALES DE ESA MARCA');
        // Retiro de OTRA marca en el mismo cupo no cuenta para esta compra (ya hay uno de la misma marca: usamos otro cupo)
    }

    public function test_r3_retiro_de_otra_marca_no_bloquea(): void
    {
        $l = $this->sembrar();
        $this->retiro($l->quota, $this->marcas[1], $this->bodega, []);
        $this->guardar([])->assertOk();
    }

    public function test_r3_mezcla_de_compras_bloquea_solo_la_afectada(): void
    {
        // 3 compras en distintos vendedores: solo la del vendedor con tarjas bloquea; las otras se pueden quitar
        $this->guardar([
            $this->compra(), $this->compra(['third_party_id' => $this->prod2->id, 'farm_id' => $this->finca2->id]),
            $this->compra(['third_party_id' => $this->asoc->id, 'farm_id' => null, 'producer_id' => $this->prod1->id, 'farm_id' => $this->finca1->id]),
        ])->assertOk();
        $lineas = ProducerQuotaLine::where('purchase_block_id', $this->idBloque(0))->get();
        $conTarja = $lineas->first(fn ($x) => $x->quota->third_party_id === $this->prod2->id);
        TallySheet::create(['producer_quota_line_id' => $conTarja->id, 'shipment_id' => $this->barco->id, 'date' => '2026-11-10', 'boxes_shipped' => 10]);
        $r = $this->guardar([]);
        $r->assertStatus(422);
        $claves = array_keys($r->json('errors'));
        $this->assertSame(["purchases.q{$conTarja->id}"], $claves);
    }

    // ───────────────────────── R4: puertos y cupos ─────────────────────────

    public function test_r4_puerto_que_no_es_de_salida_da_error(): void
    {
        $destino = Port::create(['name' => 'PUERTO DESTINO R4', 'port_type' => 'DESTINO', 'status' => true]);
        $this->assert422($this->guardar([$this->compra(['delivery_port_id' => $destino->id])]), 'EL PUERTO DE ENTREGA DEBE SER DE SALIDA');
        $this->assert422($this->guardar([$this->compra()], [], ['departure_port_id' => $destino->id]), 'EL PUERTO DEBE SER DE SALIDA');
        // Puerto de salida en el bloque sigue pasando
        $this->guardar([$this->compra()], [], ['departure_port_id' => $this->puerto->id])->assertOk();
        // Puerto inexistente: la validación base lo rechaza (422) igual que antes
        $this->guardar([$this->compra(['delivery_port_id' => 999999])])->assertStatus(422);
    }

    public function test_r4_dos_compras_mismo_vendedor_misma_semana_un_solo_cupo(): void
    {
        $this->guardar([$this->compra(), $this->compra(['boxes' => 500]), $this->compra(['third_party_id' => $this->prod2->id, 'farm_id' => $this->finca2->id])])->assertOk();
        $cupos = ProducerQuota::whereIn('third_party_id', [$this->prod1->id, $this->prod2->id])->get();
        $this->assertCount(2, $cupos);
        $c1 = $cupos->firstWhere('third_party_id', $this->prod1->id);
        $this->assertSame(46, (int) $c1->week_number);
        $this->assertSame(2026, (int) $c1->year);
        $this->assertSame($this->pedido->id, (int) $c1->customer_order_id);
        $this->assertSame(ProducerQuota::ASIGNADO, $c1->status);
        $this->assertEquals(1500, (float) $c1->total_cupo);
        $this->assertSame(2, ProducerQuotaLine::where('producer_quota_id', $c1->id)->count());
        // El mismo guardado otra vez no crea cupos nuevos
        $this->guardar([$this->compra(['id' => ProducerQuotaLine::where('producer_quota_id', $c1->id)->min('id')]),
            $this->compra(['id' => ProducerQuotaLine::where('producer_quota_id', $c1->id)->max('id'), 'boxes' => 500])])->assertOk();
        $this->assertSame(1, ProducerQuota::where('third_party_id', $this->prod1->id)->count());
    }

    public function test_r4_compra_en_semana_siguiente_va_al_cupo_de_esa_semana(): void
    {
        $this->guardar([$this->compra(), $this->compra(['purchase_week' => 47]), $this->compra(['purchase_week' => 47, 'boxes' => 300])])->assertOk();
        $cupos = ProducerQuota::where('third_party_id', $this->prod1->id)->orderBy('week_number')->get();
        $this->assertSame([46, 47], $cupos->pluck('week_number')->map(fn ($w) => (int) $w)->all());
        $this->assertEquals(1000, (float) $cupos[0]->total_cupo);
        $this->assertEquals(1300, (float) $cupos[1]->total_cupo);
        $this->assertSame($this->pedido->id, (int) $cupos[1]->customer_order_id);
        // Semana fuera del rango sigue dando 422
        $this->assert422($this->guardar([$this->compra(['purchase_week' => 48])]), 'LA SEMANA DE COMPRA');
    }

    public function test_r4_cambio_de_anio_y_cupo_existente_se_reutiliza_respetando_customer_order_id(): void
    {
        // Cupo ya existente de la semana siguiente con OTRO pedido: se reutiliza y conserva su pedido
        $otro = CustomerOrder::create(['week' => 47, 'year' => 2026, 'expected_boxes' => 100, 'status' => true]);
        $previo = ProducerQuota::create(['third_party_id' => $this->prod1->id, 'week_number' => 47, 'year' => 2026, 'total_cupo' => 0,
            'status' => ProducerQuota::ASIGNADO, 'customer_order_id' => $otro->id]);
        // Cupo existente de la semana del pedido SIN pedido: toma el de este pedido
        $sinPedido = ProducerQuota::create(['third_party_id' => $this->prod2->id, 'week_number' => 46, 'year' => 2026, 'total_cupo' => 0,
            'status' => ProducerQuota::ASIGNADO, 'customer_order_id' => null]);

        $this->guardar([$this->compra(['purchase_week' => 47]),
            $this->compra(['third_party_id' => $this->prod2->id, 'farm_id' => $this->finca2->id])])->assertOk();

        $this->assertSame(1, ProducerQuota::where('third_party_id', $this->prod1->id)->where('week_number', 47)->count());
        $this->assertSame($otro->id, (int) $previo->fresh()->customer_order_id);
        $this->assertEquals(1000, (float) $previo->fresh()->total_cupo);
        $this->assertSame($this->pedido->id, (int) $sinPedido->fresh()->customer_order_id);
        $this->assertSame(1, ProducerQuota::where('third_party_id', $this->prod2->id)->count());
    }

    public function test_r4_pedido_de_fin_de_anio_la_semana_siguiente_es_del_anio_nuevo(): void
    {
        // WK52 de 2026 → la siguiente es WK53 (2026 tiene 53 semanas ISO): se verifica con el propio cálculo del calendario
        $siguiente = \Carbon\Carbon::now()->setISODate(2026, 52)->startOfWeek()->addWeek();
        $this->pedido->update(['week' => 52]);
        $this->barco->update(['week_start' => 52, 'week_end' => 52]);
        $this->guardar([$this->compra(['purchase_week' => $siguiente->isoWeek()])])->assertOk();
        $cupo = ProducerQuota::where('third_party_id', $this->prod1->id)->firstOrFail();
        $this->assertSame($siguiente->isoWeek(), (int) $cupo->week_number);
        $this->assertSame($siguiente->isoWeekYear(), (int) $cupo->year);
    }

    public function test_r4_cuenta_de_consultas_al_guardar_muchas_compras(): void
    {
        $compras = [];
        foreach ([$this->prod1, $this->prod2] as $p) {
            $f = $p->id === $this->prod1->id ? $this->finca1 : $this->finca2;
            for ($i = 0; $i < 6; $i++) {
                $compras[] = $this->compra(['third_party_id' => $p->id, 'farm_id' => $f->id, 'boxes' => 100 + $i,
                    'purchase_week' => $i % 2 ? 47 : 46]);
            }
        }
        $version = $this->version();
        DB::enableQueryLog();
        $this->putJson($this->url(), ['version' => $version, 'blocks' => $this->bloques($compras)])->assertOk();
        $n1 = count(DB::getQueryLog());
        DB::flushQueryLog();

        // Segundo guardado (compras ya existentes → camino de los bloqueos R3)
        $existentes = ProducerQuotaLine::where('purchase_block_id', $this->idBloque(0))->orderBy('id')->get();
        $compras2 = $existentes->map(fn ($x, $i) => $this->compra([
            'id' => $x->id, 'third_party_id' => $x->quota->third_party_id, 'farm_id' => $x->quota->third_party_id === $this->prod1->id ? $this->finca1->id : $this->finca2->id,
            'boxes' => (int) $x->quantity_cajas, 'purchase_week' => (int) $x->quota->week_number,
        ]))->all();
        $version = $this->version();
        DB::flushQueryLog();
        $this->putJson($this->url(), ['version' => $version, 'blocks' => $this->bloques($compras2)])->assertOk();
        $n2 = count(DB::getQueryLog());
        DB::disableQueryLog();
        file_put_contents(sys_get_temp_dir() . '/r2_consultas.txt', "R4 consultas PUT (12 compras nuevas): $n1; PUT (12 existentes): $n2\n", FILE_APPEND);
        $this->assertSame(4, ProducerQuota::whereIn('third_party_id', [$this->prod1->id, $this->prod2->id])->count());
        $this->assertSame(12, ProducerQuotaLine::where('purchase_block_id', $this->idBloque(0))->count());
    }

    // ───────────────────────── R5: última bodega por vendedor ─────────────────────────

    public function test_r5_ultima_bodega_es_la_de_la_compra_con_id_mayor(): void
    {
        // prod1: compra 1 en bodega 1, compra 2 (id mayor) en bodega 2 → propone bodega 2
        // prod2: una compra en bodega 1 y otra SIN bodega (id mayor): se ignoran las nulas → propone bodega 1
        $this->guardar([
            $this->compra(['pickup_warehouse_id' => $this->bodega->id]),
            $this->compra(['pickup_warehouse_id' => $this->bodega2->id, 'boxes' => 500]),
            $this->compra(['third_party_id' => $this->prod2->id, 'farm_id' => $this->finca2->id, 'pickup_warehouse_id' => $this->bodega->id]),
            $this->compra(['third_party_id' => $this->prod2->id, 'farm_id' => $this->finca2->id, 'pickup_warehouse_id' => null]),
        ])->assertOk();

        $json = $this->getJson($this->url())->assertOk()->json();
        $mapa = $json['last_pickup'];
        $this->assertSame($this->bodega2->id, (int) $mapa[$this->prod1->id]);
        $this->assertSame($this->bodega->id, (int) $mapa[$this->prod2->id]);
    }

    // ───────────────────────── R6: liquidación recientes ─────────────────────────

    private function cupoLiq(string $nombre, int $anio, int $semana, bool $liquidar, bool $anulada = false): ProducerQuota
    {
        $t = ThirdParty::create(['name' => $nombre, 'type' => 'PRODUCTOR', 'status' => true]);
        $q = ProducerQuota::create(['third_party_id' => $t->id, 'week_number' => $semana, 'year' => $anio, 'total_cupo' => 0,
            'status' => $liquidar ? ProducerQuota::LIQUIDADO : ProducerQuota::ASIGNADO, 'customer_order_id' => $this->pedido->id]);
        if ($liquidar || $anulada) {
            Settlement::create(['code' => 'LIQ-T' . substr(uniqid(), -8), 'producer_quota_id' => $q->id, 'third_party_id' => $t->id,
                'week' => $semana, 'year' => $anio, 'date' => '2026-01-01', 'boxes_shipped' => 0, 'boxes_fallen' => 0, 'boxes_rejected' => 0,
                'boxes_paid' => 0, 'fruit_amount' => 0, 'materials_amount' => 0, 'advances_amount' => 0, 'net_amount' => 0,
                'invoice_amount' => 0, 'credit_note_amount' => 0, 'status' => !$anulada]);
        }
        return $q;
    }

    public function test_r6_recientes_devuelve_sin_liquidar_de_cualquier_semana_y_liquidados_de_8_semanas(): void
    {
        $hoy = now();
        $reciente = $hoy->copy()->subWeeks(3);
        $limite = $hoy->copy()->subWeeks(8);
        $viejo = $hoy->copy()->subWeeks(20);
        $muyViejo = $hoy->copy()->subWeeks(70);

        $ids = [
            'liq_reciente'      => $this->cupoLiq('R6-LIQ RECIENTE', $reciente->isoWeekYear, $reciente->isoWeek, true)->id,
            'liq_limite'        => $this->cupoLiq('R6-LIQ LIMITE', $limite->isoWeekYear, $limite->isoWeek, true)->id,
            'liq_viejo'         => $this->cupoLiq('R6-LIQ VIEJO', $viejo->isoWeekYear, $viejo->isoWeek, true)->id,
            'liq_muy_viejo'     => $this->cupoLiq('R6-LIQ MUY VIEJO', $muyViejo->isoWeekYear, $muyViejo->isoWeek, true)->id,
            'abierto_viejo'     => $this->cupoLiq('R6-ABIERTO VIEJO', $viejo->isoWeekYear, $viejo->isoWeek, false)->id,
            'abierto_muy_viejo' => $this->cupoLiq('R6-ABIERTO MUY VIEJO', $muyViejo->isoWeekYear, $muyViejo->isoWeek, false)->id,
            'anulada_vieja'     => $this->cupoLiq('R6-ANULADA VIEJA', $viejo->isoWeekYear, $viejo->isoWeek, false, true)->id,
        ];

        $todos = collect($this->getJson('/api/settlements/quotas')->assertOk()->json())->pluck('id');
        foreach ($ids as $n => $id) {
            $this->assertTrue($todos->contains($id), "Sin el parámetro debe devolver todo: falta $n");
        }

        $rec = collect($this->getJson('/api/settlements/quotas?recientes=1')->assertOk()->json())->pluck('id');
        foreach (['liq_reciente', 'liq_limite', 'abierto_viejo', 'abierto_muy_viejo', 'anulada_vieja'] as $n) {
            $this->assertTrue($rec->contains($ids[$n]), "recientes=1 debe incluir $n");
        }
        foreach (['liq_viejo', 'liq_muy_viejo'] as $n) {
            $this->assertFalse($rec->contains($ids[$n]), "recientes=1 debe excluir $n");
        }
        // recientes=0 / false = todo
        $cero = collect($this->getJson('/api/settlements/quotas?recientes=0')->assertOk()->json())->pluck('id');
        $this->assertTrue($cero->contains($ids['liq_muy_viejo']));
        // Combinado con week/year sigue filtrando
        $r = collect($this->getJson("/api/settlements/quotas?recientes=1&year={$viejo->isoWeekYear}&week={$viejo->isoWeek}")->assertOk()->json())->pluck('id');
        $this->assertTrue($r->contains($ids['abierto_viejo']));
        $this->assertFalse($r->contains($ids['liq_reciente']));
    }
}
