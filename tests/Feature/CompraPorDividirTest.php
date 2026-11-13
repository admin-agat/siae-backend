<?php

// 13/11/2026: pruebas de "compra POR DIVIDIR" + "Dividir compra".
// Diseño: siae-frontend/docs/modulos/compra-global-division.md (§5.1, §5.2, §6, §7), aprobado por Peter.
//
//   PUT  /api/customer-orders/{id}/purchases                 → blocks.*.purchases.*.pending_split
//   POST /api/customer-orders/{id}/purchases/{lineId}/split  → {version, remainder: REDUCE|KEEP, rows:[...]}
//
// Base: siae_test (solo estructura, sin datos). DatabaseTransactions: cada prueba arma sus propios datos y
// todo se deshace al terminar. NO se usa RefreshDatabase.
//
// Supuestos donde el contrato era ambiguo (se eligió lo del diseño):
//  - Los 422 se leen en `errors.*` (ValidationException) y todos los mensajes deben estar en MAYÚSCULAS.
//  - El bloque PACKED exige `real_price` en la fila SOLO si no es el bloque original de la compra.
//  - Suma de filas > cajas de la compra se permite (aviso ámbar en pantalla, no bloquea).
//  - `pending_split = true` con solo productor (sin finca) sigue POR DIVIDIR y guarda productor/finca nulos.
//  - Las filas nuevas de la misma marca heredan el envío a bodega de la original; las de marca nueva se
//    marcan enviadas y generan su aviso en user_notifications.

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Contract;
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
use App\Models\UserNotification;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompraPorDividirTest extends TestCase
{
    use DatabaseTransactions;

    private static int $n = 0;

    private User $comercial;
    private User $bodeguero;
    private CustomerOrder $pedido;
    private ThirdParty $asoc;        // COMERCIALIZADORA (vendedor)
    private ThirdParty $prod1;       // PRODUCTOR
    private ThirdParty $prod2;       // PRODUCTOR
    private Farm $finca1;
    private Farm $finca2;
    private Warehouse $bodega;
    private Port $puerto;
    private Customer $cliente;
    /** @var Brand[] 0 = convencional A, 1 = packed (banda), 2 = convencional B (marca nueva) */
    private array $marcas = [];
    private Shipment $barco;

    protected function setUp(): void
    {
        parent::setUp();
        // Confirma que NO corre contra otra base
        $this->assertSame('siae_test', \DB::connection()->getDatabaseName());
        $this->armar();
        Sanctum::actingAs($this->comercial);
    }

    // ───────────────────────── Datos de cada prueba ─────────────────────────

    private function armar(): void
    {
        $k = ++self::$n . '-' . strtoupper(substr(uniqid(), -6));

        $this->comercial = $this->usuario('COMERCIAL', "comercial$k@test.local");
        $this->bodeguero = $this->usuario('BODEGUERO', "bodeguero$k@test.local");
        $this->bodega = Warehouse::create(['name' => "BODEGA T$k", 'code' => "BT$k", 'responsible_user_id' => $this->bodeguero->id, 'status' => true]);
        $this->puerto = Port::create(['name' => "PUERTO T$k", 'port_type' => 'SALIDA', 'status' => true]);

        $this->asoc = ThirdParty::create(['name' => "ASOCIACION T$k", 'type' => 'COMERCIALIZADORA', 'status' => true]);
        $this->prod1 = ThirdParty::create(['name' => "PRODUCTOR UNO T$k", 'type' => 'PRODUCTOR', 'status' => true]);
        $this->prod2 = ThirdParty::create(['name' => "PRODUCTOR DOS T$k", 'type' => 'PRODUCTOR', 'status' => true]);
        $this->finca1 = Farm::create(['third_party_id' => $this->prod1->id, 'name' => "FINCA UNO T$k", 'magap_code' => 'C-0001', 'status' => true]);
        $this->finca2 = Farm::create(['third_party_id' => $this->prod2->id, 'name' => "FINCA DOS T$k", 'magap_code' => 'C-0002', 'status' => true]);

        $clienteTercero = ThirdParty::create(['name' => "CLIENTE T$k", 'type' => 'CLIENTE', 'status' => true]);
        $this->cliente = Customer::create(['customer_code' => "CUST-T$k", 'third_party_id' => $clienteTercero->id,
            'country' => 'RUSIA', 'negotiation_type' => 'CONTRATO', 'status' => true]);
        foreach (['A', 'P', 'B'] as $i => $letra) {
            $this->marcas[$i] = Brand::create(['name' => "MARCA $letra T$k", 'code' => "M{$letra}T$k", 'status' => true]);
            $this->cliente->brands()->attach($this->marcas[$i]->id);
        }

        $this->pedido = CustomerOrder::create(['week' => 46, 'year' => 2026, 'expected_boxes' => 20000, 'status' => true]);
        CustomerOrderLine::create([
            'customer_order_id' => $this->pedido->id, 'customer_id' => $this->cliente->id, 'brand_id' => $this->marcas[0]->id,
            'presentation' => 'CONVENCIONAL', 'boxes' => 20000, 'incoterm' => 'FOB', 'cargo_type' => 'CAJA_BAJO_CUBIERTA',
            'negotiation_type' => 'CONTRATO',
        ]);
        $linea = ShippingLine::create(['name' => "NAVIERA T$k"]);
        $this->barco = Shipment::create([
            'code' => "SH$k", 'registration_date' => '2026-11-09', 'departure_date' => '2026-11-20', 'vessel_name' => "NAVE T$k",
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

    private function compra(array $extra = []): array
    {
        return $extra + [
            'third_party_id' => $this->asoc->id, 'boxes' => 7000, 'official_price' => 7.5, 'real_price' => 7.1,
            'negotiation_type' => 'SPOT', 'payment_scheme' => '100', 'pickup_warehouse_id' => $this->bodega->id,
            'delivery_port_id' => $this->puerto->id, 'pending_split' => true,
        ];
    }

    private function idBloque(int $i): ?int
    {
        return PurchaseBlock::where('customer_order_id', $this->pedido->id)->where('brand_id', $this->marcas[$i]->id)->value('id');
    }

    /** Tres bloques del pedido: 0 convencional A, 1 PACKED, 2 convencional B. Las compras de cada uno se pasan aparte. */
    private function bloques(array $compras0, array $compras1 = [], array $compras2 = []): array
    {
        $def = [[0, 'CONVENCIONAL', $compras0], [1, 'PACKED', $compras1], [2, 'CONVENCIONAL', $compras2]];
        return array_map(fn ($d) => [
            'id' => $this->idBloque($d[0]), 'shipment_id' => $this->barco->id, 'brand_id' => $this->marcas[$d[0]]->id,
            'presentation' => $d[1], 'customer_id' => $this->cliente->id, 'cargo_mode' => 'CAJA_BAJO_CUBIERTA',
            'target_boxes' => 7000, 'purchases' => $d[2],
        ], $def);
    }

    private function guardar(array $compras0, array $compras1 = [], array $compras2 = [], ?string $version = null): TestResponse
    {
        return $this->putJson($this->url(), ['version' => $version ?? $this->version(),
            'blocks' => $this->bloques($compras0, $compras1, $compras2)]);
    }

    /** Siembra la compra global POR DIVIDIR (o la que se pase) y devuelve su línea. */
    private function sembrar(array $extra = []): ProducerQuotaLine
    {
        $this->guardar([$this->compra($extra)])->assertOk();
        return ProducerQuotaLine::where('purchase_block_id', $this->idBloque(0))->firstOrFail();
    }

    private function enviar(ProducerQuotaLine $l): void
    {
        $this->postJson($this->url('/send'), ['purchase_ids' => [$l->id]])->assertOk();
    }

    private function fila(int $bloque, ThirdParty $prod, Farm $finca, int $cajas, array $extra = []): array
    {
        return $extra + ['purchase_block_id' => $this->idBloque($bloque), 'producer_id' => $prod->id,
            'farm_id' => $finca->id, 'boxes' => $cajas];
    }

    private function dividir(ProducerQuotaLine $l, array $filas, ?string $remainder = null, ?string $version = null): TestResponse
    {
        $body = ['version' => $version ?? $this->version(), 'rows' => $filas];
        if ($remainder) {
            $body['remainder'] = $remainder;
        }
        return $this->postJson($this->url("/{$l->id}/split"), $body);
    }

    /** 422 con el fragmento esperado y TODOS los mensajes en MAYÚSCULAS. */
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

    private function lineas()
    {
        return ProducerQuotaLine::whereIn('purchase_block_id', PurchaseBlock::where('customer_order_id', $this->pedido->id)->pluck('id'))
            ->orderBy('id')->get();
    }

    private function totalCupo(): float
    {
        return (float) ProducerQuota::where('third_party_id', $this->asoc->id)->where('year', 2026)->where('week_number', 46)->value('total_cupo');
    }

    // ───────────────────────── PUT: compra POR DIVIDIR ─────────────────────────

    public function test_por_dividir_solo_comercializadora(): void
    {
        $r = $this->guardar([$this->compra(['third_party_id' => $this->prod1->id, 'farm_id' => $this->finca1->id])]);
        $this->assert422($r, 'SOLO UNA COMPRA A UNA COMERCIALIZADORA PUEDE QUEDAR POR DIVIDIR');
        $this->assertSame(0, $this->lineas()->count());
    }

    public function test_pending_split_no_exige_productor_ni_finca(): void
    {
        $l = $this->sembrar();
        $this->assertTrue((bool) $l->pending_split);
        $this->assertNull($l->producer_id);
        $this->assertNull($l->farm_id);

        $compra = $this->getJson($this->url())->assertOk()->json('blocks.0.purchases.0');
        $this->assertTrue($compra['pending_split']);
    }

    public function test_pending_split_ignora_productor_si_no_viene_la_finca(): void
    {
        // Solo productor, sin finca: sigue POR DIVIDIR y se guardan nulos (el CHECK de la base lo exige)
        $l = $this->sembrar(['producer_id' => $this->prod1->id]);
        $this->assertTrue((bool) $l->pending_split);
        $this->assertNull($l->producer_id);
        $this->assertNull($l->farm_id);
    }

    public function test_sin_pending_split_sigue_exigiendo_productor_y_finca(): void
    {
        $r = $this->guardar([$this->compra(['pending_split' => false])]);
        $this->assert422($r, 'ELIGE EL PRODUCTOR REAL');
        $this->assertSame(0, $this->lineas()->count());
    }

    public function test_si_llegan_productor_y_finca_pending_split_pasa_a_false(): void
    {
        $l = $this->sembrar(['producer_id' => $this->prod1->id, 'farm_id' => $this->finca1->id]); // pending_split = true
        $this->assertFalse((bool) $l->pending_split);
        $this->assertSame($this->prod1->id, (int) $l->producer_id);
        $this->assertSame($this->finca1->id, (int) $l->farm_id);
    }

    // ───────────────────────── Dividir: caso feliz ─────────────────────────

    public function test_dividir_exacto_en_el_mismo_bloque(): void
    {
        $original = $this->sembrar(['executive' => null, 'notes' => 'COMPRA GLOBAL']);
        $idOriginal = $original->id;

        $r = $this->dividir($original, [
            $this->fila(0, $this->prod1, $this->finca1, 3000, ['notes' => 'zona puerto inca · acopio c-0040']),
            $this->fila(0, $this->prod2, $this->finca2, 4000),
        ]);
        $r->assertOk()->assertJsonPath('divididas', 2)->assertJsonPath('saldo', 0);

        $lineas = $this->lineas();
        $this->assertCount(2, $lineas);

        // La original conserva su id y toma la primera fila
        $original = $lineas->firstWhere('id', $idOriginal);
        $this->assertNotNull($original);
        $this->assertSame(3000, (int) $original->quantity_cajas);
        $this->assertSame($this->prod1->id, (int) $original->producer_id);
        $this->assertSame($this->finca1->id, (int) $original->farm_id);
        $this->assertSame("FINCA UNO", substr($original->farm_name, 0, 9));
        $this->assertSame('C-0001', $original->mag_code);
        $this->assertFalse((bool) $original->pending_split);
        $this->assertSame('ZONA PUERTO INCA · ACOPIO C-0040', $original->notes); // notas en MAYÚSCULAS
        $this->assertNotNull($original->split_at);
        $this->assertSame($this->comercial->id, (int) $original->split_by);

        // La fila nueva sale de la original y copia precio, forma de pago, envío y bodega
        $nueva = $lineas->firstWhere('id', '!=', $idOriginal);
        $this->assertSame($idOriginal, (int) $nueva->split_from_line_id);
        $this->assertSame(4000, (int) $nueva->quantity_cajas);
        $this->assertSame($this->prod2->id, (int) $nueva->producer_id);
        $this->assertSame($this->finca2->id, (int) $nueva->farm_id);
        $this->assertSame((int) $original->producer_quota_id, (int) $nueva->producer_quota_id); // mismo cupo (vendedor + semana)
        $this->assertSame((int) $original->purchase_block_id, (int) $nueva->purchase_block_id);
        $this->assertEquals(7.5, (float) $nueva->official_price);
        $this->assertEquals(7.1, (float) $nueva->real_price);
        $this->assertSame('100', $nueva->payment_scheme);
        $this->assertSame('SPOT', $nueva->negotiation_type);
        $this->assertSame($this->bodega->id, (int) $nueva->pickup_warehouse_id);
        $this->assertSame($this->puerto->id, (int) $nueva->delivery_port_id);
        $this->assertNotNull($nueva->split_at);
        $this->assertSame($this->comercial->id, (int) $nueva->split_by);
        $this->assertFalse((bool) $nueva->pending_split);

        $this->assertEquals(7000, $this->totalCupo());
    }

    public function test_dividir_copia_el_contrato_y_la_parte(): void
    {
        $contrato = Contract::create(['third_party_id' => $this->asoc->id, 'weekly_boxes' => 10000, 'number' => 1, 'status' => true]);
        $original = $this->sembrar(['negotiation_type' => 'CONTRATO', 'contract_id' => $contrato->id, 'contract_part' => 1]);

        $this->dividir($original, [
            $this->fila(0, $this->prod1, $this->finca1, 3000),
            $this->fila(0, $this->prod2, $this->finca2, 4000),
        ])->assertOk();

        foreach ($this->lineas() as $l) {
            $this->assertSame('CONTRATO', $l->negotiation_type);
            $this->assertSame($contrato->id, (int) $l->contract_id);
            $this->assertSame(1, (int) $l->contract_part);
        }
    }

    public function test_dividir_deja_bitacora_con_origen(): void
    {
        $original = $this->sembrar();
        $this->dividir($original, [
            $this->fila(0, $this->prod1, $this->finca1, 3000),
            $this->fila(0, $this->prod2, $this->finca2, 4000),
        ])->assertOk();

        $nueva = $this->lineas()->firstWhere('id', '!=', $original->id);
        $crear = AuditLog::where('table_name', 'producer_quota_lines')->where('record_id', $nueva->id)->where('action', 'CREAR')->first();
        $this->assertNotNull($crear, 'La fila nueva no quedó en audit_logs.');
        $cambios = is_string($crear->changes) ? json_decode($crear->changes, true) : (array) $crear->changes;
        $this->assertEquals($original->id, $cambios['split_from_line_id'] ?? null);

        $editar = AuditLog::where('table_name', 'producer_quota_lines')->where('record_id', $original->id)->where('action', 'EDITAR')->get()
            ->first(function ($a) {
                $c = is_string($a->changes) ? json_decode($a->changes, true) : (array) $a->changes;
                return array_key_exists('quantity_cajas', $c) && array_key_exists('pending_split', $c);
            });
        $this->assertNotNull($editar, 'El cambio de la compra original no quedó en audit_logs.');
    }

    // ───────────────────────── Dividir: saldo (REDUCE / KEEP) ─────────────────────────

    public function test_dividir_que_suma_menos_exige_elegir_reduce_o_keep(): void
    {
        $original = $this->sembrar();
        $r = $this->dividir($original, [
            $this->fila(0, $this->prod1, $this->finca1, 3000),
            $this->fila(0, $this->prod2, $this->finca2, 3400),
        ]);
        $this->assert422($r, 'LAS FILAS SUMAN 6.400 DE 7.000 CAJAS: ELIGE SI BAJAS LA COMPRA O DEJAS EL SALDO POR DIVIDIR');
        $this->assertSame(1, $this->lineas()->count()); // nada se guardó
    }

    public function test_dividir_reduce_baja_la_compra(): void
    {
        $original = $this->sembrar();
        $this->dividir($original, [
            $this->fila(0, $this->prod1, $this->finca1, 3000),
            $this->fila(0, $this->prod2, $this->finca2, 3400),
        ], 'REDUCE')->assertOk()->assertJsonPath('saldo', 0);

        $lineas = $this->lineas();
        $this->assertCount(2, $lineas);
        $this->assertSame(6400, (int) $lineas->sum('quantity_cajas'));
        $this->assertSame(0, $lineas->where('pending_split', true)->count());
        $this->assertEquals(6400, $this->totalCupo());
    }

    public function test_dividir_keep_deja_el_saldo_por_dividir(): void
    {
        $original = $this->sembrar();
        $this->dividir($original, [
            $this->fila(0, $this->prod1, $this->finca1, 3000),
            $this->fila(0, $this->prod2, $this->finca2, 3400),
        ], 'KEEP')->assertOk()->assertJsonPath('divididas', 2)->assertJsonPath('saldo', 600);

        $lineas = $this->lineas();
        $this->assertCount(3, $lineas);
        $saldo = $lineas->firstWhere('pending_split', true);
        $this->assertNotNull($saldo);
        $this->assertSame(600, (int) $saldo->quantity_cajas);
        $this->assertNull($saldo->producer_id);
        $this->assertNull($saldo->farm_id);
        $this->assertSame($original->id, (int) $saldo->split_from_line_id);
        $this->assertSame((int) $original->purchase_block_id, (int) $saldo->purchase_block_id);
        $this->assertSame(1, $lineas->where('pending_split', true)->count());
        $this->assertEquals(7000, $this->totalCupo()); // el saldo sigue contando en el cupo
    }

    public function test_dividir_keep_no_aplica_a_compra_directa_a_productor(): void
    {
        // Compra directa al productor 1 (no es comercializadora): solo se divide por finca
        $finca1b = Farm::create(['third_party_id' => $this->prod1->id, 'name' => 'FINCA UNO B', 'status' => true]);
        $this->guardar([$this->compra(['third_party_id' => $this->prod1->id, 'pending_split' => false, 'farm_id' => $this->finca1->id])])->assertOk();
        $original = ProducerQuotaLine::where('purchase_block_id', $this->idBloque(0))->firstOrFail();

        $filas = [
            ['purchase_block_id' => $this->idBloque(0), 'farm_id' => $this->finca1->id, 'boxes' => 3000],
            ['purchase_block_id' => $this->idBloque(0), 'farm_id' => $finca1b->id, 'boxes' => 3400],
        ];
        $this->assert422($this->dividir($original, $filas, 'KEEP'), 'SOLO UNA COMPRA A UNA COMERCIALIZADORA PUEDE DEJAR SALDO POR DIVIDIR');
    }

    public function test_dividir_que_suma_mas_se_permite(): void
    {
        $original = $this->sembrar();
        $this->dividir($original, [
            $this->fila(0, $this->prod1, $this->finca1, 4000),
            $this->fila(0, $this->prod2, $this->finca2, 3500),
        ])->assertOk();

        $this->assertSame(7500, (int) $this->lineas()->sum('quantity_cajas'));
        $this->assertEquals(7500, $this->totalCupo());
    }

    // ───────────────────────── Dividir: PACKED, productor y finca ─────────────────────────

    public function test_fila_a_bloque_packed_es_spot_sin_contrato_y_exige_precio_real(): void
    {
        $contrato = Contract::create(['third_party_id' => $this->asoc->id, 'weekly_boxes' => 10000, 'number' => 1, 'status' => true]);
        $this->guardar([$this->compra(['negotiation_type' => 'CONTRATO', 'contract_id' => $contrato->id])], [])->assertOk();
        $original = ProducerQuotaLine::where('purchase_block_id', $this->idBloque(0))->firstOrFail();

        // Sin precio real en la fila PACKED → 422
        $r = $this->dividir($original, [
            $this->fila(0, $this->prod1, $this->finca1, 6000),
            $this->fila(1, $this->prod2, $this->finca2, 1000),
        ]);
        $this->assert422($r, 'LA FRUTA PACKED SE COMPRA SIEMPRE SPOT: ESCRIBE EL PRECIO REAL');
        $this->assertSame(1, $this->lineas()->count());

        // Con precio real → la fila PACKED queda SPOT, sin contrato, con su precio
        $this->dividir($original, [
            $this->fila(0, $this->prod1, $this->finca1, 6000),
            $this->fila(1, $this->prod2, $this->finca2, 1000, ['real_price' => 6.25]),
        ])->assertOk();

        $packed = $this->lineas()->firstWhere('purchase_block_id', $this->idBloque(1));
        $this->assertNotNull($packed);
        $this->assertSame('SPOT', $packed->negotiation_type);
        $this->assertNull($packed->contract_id);
        $this->assertSame(0, (int) $packed->contract_part);
        $this->assertEquals(6.25, (float) $packed->real_price);
        $this->assertSame($this->marcas[1]->id, (int) $packed->brand_id);
        $this->assertSame($this->marcas[1]->code, $packed->variety);
        $this->assertSame($original->id, (int) $packed->split_from_line_id);

        // La fila que se queda en el bloque original conserva el contrato
        $queda = $this->lineas()->firstWhere('id', $original->id);
        $this->assertSame('CONTRATO', $queda->negotiation_type);
        $this->assertSame($contrato->id, (int) $queda->contract_id);
    }

    public function test_productor_que_no_es_productor_activo_da_422(): void
    {
        $original = $this->sembrar();
        $otraAsoc = ThirdParty::create(['name' => 'OTRA ASOCIACION', 'type' => 'COMERCIALIZADORA', 'status' => true]);
        $r = $this->dividir($original, [
            $this->fila(0, $otraAsoc, $this->finca1, 3000),
            $this->fila(0, $this->prod2, $this->finca2, 4000),
        ]);
        $this->assert422($r, 'NO ES UN PRODUCTOR ACTIVO');

        $this->prod2->update(['status' => false]); // productor desactivado
        $r = $this->dividir($original, [
            $this->fila(0, $this->prod1, $this->finca1, 3000),
            $this->fila(0, $this->prod2, $this->finca2, 4000),
        ]);
        $this->assert422($r, 'NO ES UN PRODUCTOR ACTIVO');
        $this->assertSame(1, $this->lineas()->count());
    }

    public function test_fila_sin_productor_o_sin_finca_da_422(): void
    {
        $original = $this->sembrar();
        $r = $this->dividir($original, [
            ['purchase_block_id' => $this->idBloque(0), 'farm_id' => $this->finca1->id, 'boxes' => 3000],
            $this->fila(0, $this->prod2, $this->finca2, 4000),
        ]);
        $this->assert422($r, 'FILA 1: ELIGE EL PRODUCTOR');

        $r = $this->dividir($original, [
            $this->fila(0, $this->prod1, $this->finca1, 3000),
            ['purchase_block_id' => $this->idBloque(0), 'producer_id' => $this->prod2->id, 'boxes' => 4000],
        ]);
        $this->assert422($r, 'FILA 2: FALTA LA FINCA');
    }

    public function test_finca_de_otro_productor_da_422(): void
    {
        $original = $this->sembrar();
        $r = $this->dividir($original, [
            $this->fila(0, $this->prod1, $this->finca2, 3000), // finca2 es de prod2
            $this->fila(0, $this->prod2, $this->finca2, 4000),
        ]);
        $this->assert422($r, 'LA FINCA');
        $this->assertTrue(collect($r->json('errors'))->flatten()->contains(fn ($m) => str_contains($m, 'NO ES DE')));
        $this->assertSame(1, $this->lineas()->count());
    }

    public function test_cuadro_con_bloque_ajeno_sin_bloque_original_o_una_sola_fila(): void
    {
        $original = $this->sembrar();

        $r = $this->dividir($original, [
            $this->fila(0, $this->prod1, $this->finca1, 3000),
            $this->fila(0, $this->prod2, $this->finca2, 4000, ['purchase_block_id' => 999999999]),
        ]);
        $this->assert422($r, 'EL BLOQUE NO ES DE ESTE PEDIDO');

        // Ninguna fila queda en el bloque de la compra original (marca A)
        // (el primer guardado ya creó los tres bloques del pedido, incluido el de la marca B)
        $r = $this->dividir($original, [
            $this->fila(2, $this->prod1, $this->finca1, 3000),
            $this->fila(2, $this->prod2, $this->finca2, 4000),
        ]);
        $this->assert422($r, 'AL MENOS UNA FILA DEBE QUEDAR EN EL BLOQUE ORIGINAL');

        $r = $this->dividir($original, [$this->fila(0, $this->prod1, $this->finca1, 7000)]);
        $this->assert422($r, 'EL CUADRO DEBE TENER AL MENOS DOS FILAS');
    }

    public function test_cajas_en_cero_da_422_en_mayusculas(): void
    {
        $original = $this->sembrar();
        $r = $this->dividir($original, [
            $this->fila(0, $this->prod1, $this->finca1, 0),
            $this->fila(0, $this->prod2, $this->finca2, 7000),
        ]);
        $this->assert422($r, 'LAS CAJAS DEBEN SER MAYORES A CERO');
    }

    // ───────────────────────── Bloqueos, versión y permisos ─────────────────────────

    public function test_no_se_divide_con_tarjas(): void
    {
        $original = $this->sembrar();
        TallySheet::create(['producer_quota_line_id' => $original->id, 'date' => '2026-11-12',
            'boxes_shipped' => 100, 'boxes_fallen' => 0, 'boxes_rejected' => 0]);

        $r = $this->dividir($original, [
            $this->fila(0, $this->prod1, $this->finca1, 3000),
            $this->fila(0, $this->prod2, $this->finca2, 4000),
        ]);
        $this->assert422($r, 'NO SE PUEDE DIVIDIR: LA COMPRA YA TIENE TARJAS');
        $this->assertSame(1, $this->lineas()->count());
        $this->assertSame(7000, (int) $original->fresh()->quantity_cajas);
    }

    public function test_no_se_divide_con_el_cupo_liquidado(): void
    {
        $original = $this->sembrar();
        $original->quota->update(['status' => ProducerQuota::LIQUIDADO]);

        $r = $this->dividir($original, [
            $this->fila(0, $this->prod1, $this->finca1, 3000),
            $this->fila(0, $this->prod2, $this->finca2, 4000),
        ]);
        $this->assert422($r, 'YA FUE LIQUIDADO');
        $this->assertSame(1, $this->lineas()->count());
    }

    public function test_version_desactualizada_da_409_y_no_guarda(): void
    {
        $original = $this->sembrar();
        $r = $this->dividir($original, [
            $this->fila(0, $this->prod1, $this->finca1, 3000),
            $this->fila(0, $this->prod2, $this->finca2, 4000),
        ], null, 'version-vieja');
        $r->assertStatus(409)->assertJsonPath('conflict', true);
        $this->assertSame(1, $this->lineas()->count());
        $this->assertTrue((bool) $original->fresh()->pending_split);
    }

    public function test_compra_inexistente_o_pedido_anulado_da_422(): void
    {
        $original = $this->sembrar();
        $filas = [
            $this->fila(0, $this->prod1, $this->finca1, 3000),
            $this->fila(0, $this->prod2, $this->finca2, 4000),
        ];

        $inexistente = clone $original;
        $inexistente->id = 999999999;
        $this->assert422($this->dividir($inexistente, $filas), 'LA COMPRA NO EXISTE O NO ES DE ESTA SEMANA');

        $this->pedido->update(['status' => false]);
        $r = $this->dividir($original, $filas)->assertStatus(422);
        $this->assertSame('EL PEDIDO ESTÁ ANULADO.', $r->json('message'));
    }

    public function test_bodeguero_no_puede_dividir_sin_la_pantalla_compra_fruta(): void
    {
        $original = $this->sembrar();
        $filas = [
            $this->fila(0, $this->prod1, $this->finca1, 3000),
            $this->fila(0, $this->prod2, $this->finca2, 4000),
        ];
        $version = $this->version();

        Sanctum::actingAs($this->bodeguero);
        $this->dividir($original, $filas, null, $version)->assertStatus(403);
        $this->assertSame(1, $this->lineas()->count());

        // ADMIN entra a todo
        Sanctum::actingAs($this->usuario('ADMIN', 'admin' . self::$n . '@test.local'));
        $this->dividir($original, $filas, null, $version)->assertOk();
    }

    // ───────────────────────── Bodega: avisos y CAMBIÓ ─────────────────────────

    public function test_marca_nueva_con_compra_enviada_genera_aviso_y_marca_cambio(): void
    {
        $original = $this->sembrar();
        $this->enviar($original);
        $antes = UserNotification::where('user_id', $this->bodeguero->id)->count();
        $this->assertSame(1, $antes, 'El envío debería avisar al bodeguero de la bodega.');

        // 4.000 de la marca A y 3.000 de la marca B (marca nueva para la bodega)
        $this->dividir($original->fresh(), [
            $this->fila(0, $this->prod1, $this->finca1, 4000),
            $this->fila(2, $this->prod2, $this->finca2, 3000),
        ])->assertOk();

        $nueva = $this->lineas()->firstWhere('id', '!=', $original->id);
        $this->assertSame($this->marcas[2]->id, (int) $nueva->brand_id);
        $this->assertNotNull($nueva->sent_to_warehouse_at, 'La fila de marca nueva debe quedar enviada.');

        $avisos = UserNotification::where('user_id', $this->bodeguero->id)->get();
        $this->assertCount($antes + 1, $avisos);
        $this->assertNotNull($avisos->firstWhere('producer_quota_line_id', $nueva->id), 'Falta el aviso de la fila de marca nueva.');

        // La marca A bajó de 7.000 a 4.000: la bandeja debe mostrar CAMBIÓ
        $this->assertNotNull($original->fresh()->changed_after_sent_at);
    }

    public function test_misma_marca_exacta_no_marca_cambio_ni_avisa(): void
    {
        $original = $this->sembrar();
        $this->enviar($original);
        $antes = UserNotification::where('user_id', $this->bodeguero->id)->count();

        $this->dividir($original->fresh(), [
            $this->fila(0, $this->prod1, $this->finca1, 3000),
            $this->fila(0, $this->prod2, $this->finca2, 4000),
        ])->assertOk();

        $this->assertSame($antes, UserNotification::where('user_id', $this->bodeguero->id)->count());
        foreach ($this->lineas() as $l) {
            $this->assertNull($l->changed_after_sent_at, "La fila {$l->id} no debía quedar como CAMBIÓ.");
            $this->assertNotNull($l->sent_to_warehouse_at, "La fila {$l->id} hereda el envío a bodega.");
        }
    }

    // ───────────────────────── Tablero (AL5/AL5b) y aviso de liquidación ─────────────────────────

    public function test_tablero_cuenta_por_dividir_en_al5b_y_no_en_al5(): void
    {
        $this->sembrar(); // 7.000 cajas POR DIVIDIR de la comercializadora
        Sanctum::actingAs($this->usuario('ADMIN', 'admintab' . self::$n . '@test.local'));

        $alertas = collect($this->getJson('/api/boards/operations?year=2026&week_from=46&week_to=46')->assertOk()->json('alertas'));
        $al5b = $alertas->firstWhere('codigo', 'AL5b');
        $this->assertNotNull($al5b, 'Falta la alerta AL5b.');
        $this->assertSame(1, (int) $al5b['cantidad']);
        $this->assertEquals(7000, $al5b['cajas']);
        $this->assertNull($alertas->firstWhere('codigo', 'AL5'), 'La compra POR DIVIDIR no debe contar en AL5.');
    }

    public function test_preview_de_liquidacion_avisa_cajas_por_dividir(): void
    {
        $l = $this->sembrar();
        $filas = [
            $this->fila(0, $this->prod1, $this->finca1, 3000),
            $this->fila(0, $this->prod2, $this->finca2, 4000),
        ];
        $version = $this->version();
        Sanctum::actingAs($this->usuario('ADMIN', 'adminliq' . self::$n . '@test.local'));

        $avisos = $this->getJson('/api/settlements/preview/' . $l->producer_quota_id)->assertOk()->json('avisos');
        $this->assertCount(1, $avisos);
        $this->assertStringContainsString('HAY 7.000 CAJAS POR DIVIDIR DE ' . $this->asoc->name, $avisos[0]);
        $this->assertSame(mb_strtoupper($avisos[0]), $avisos[0]);

        // Dividida del todo: ya no hay aviso
        $this->dividir($l, $filas, null, $version)->assertOk();
        $this->assertSame([], $this->getJson('/api/settlements/preview/' . $l->producer_quota_id)->assertOk()->json('avisos'));
    }

    public function test_quitar_una_compra_enviada_con_avisos_ya_no_da_500(): void
    {
        $l = $this->sembrar();
        $this->enviar($l);
        $this->assertSame(1, UserNotification::where('producer_quota_line_id', $l->id)->count());

        // Comercial quita la compra (el bloque queda sin compras)
        $this->guardar([])->assertOk();

        $this->assertNull(ProducerQuotaLine::find($l->id));
        $aviso = UserNotification::where('user_id', $this->bodeguero->id)->first();
        $this->assertNotNull($aviso, 'El aviso se conserva.');
        $this->assertNull($aviso->producer_quota_line_id, 'La FK pasa a NULL (ON DELETE SET NULL).');
    }
}
