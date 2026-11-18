<?php

// 18/11/2026 (fase 2b): cuenta bancaria y ficha del vendedor desde Liquidaciones.
//   POST/GET /api/producer-bank-accounts/{id}, PUT /api/producer-profiles/{id}, /api/producer-contracts,
//   pay() bloqueado sin cuenta o sin cédula, bitácora enmascarada, Contabilidad ve la cuenta completa.
// Base: siae_test con DatabaseTransactions (nunca RefreshDatabase). Cada prueba arma sus datos.

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bank;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderLine;
use App\Models\Farm;
use App\Models\Port;
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
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CuentaBancariaLiquidacionesTest extends TestCase
{
    use DatabaseTransactions;

    private static int $n = 0;

    // Valor de prueba (no real)
    private const NUMERO = '2200987654321';

    private string $cedulaTitular;
    private Bank $banco;
    private ThirdParty $prod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('siae_test', DB::connection()->getDatabaseName());
        ++self::$n;
        $this->banco = Bank::create(['name' => 'BANCO PRUEBA ' . uniqid(), 'code' => '9' . random_int(100, 999), 'status' => true]);
        $this->prod = ThirdParty::create(['name' => 'PRODUCTOR CB ' . uniqid(), 'type' => 'PRODUCTOR', 'status' => true, 'retention_regime' => 'NORMAL']);
        $this->cedulaTitular = $this->cedulaUnica();
    }

    // ───────────────────────── Ayudas ─────────────────────────

    private function usuario(string $rol, array $extra = []): User
    {
        return User::create(['name' => "USUARIO $rol", 'email' => strtolower($rol) . self::$n . uniqid() . '@test.local', 'password' => 'Clave-Test-123',
            'role' => $rol, 'status' => true, 'must_change_password' => false, 'password_changed_at' => now()] + $extra);
    }

    private function como(string $rol): User
    {
        $u = $this->usuario($rol);
        Sanctum::actingAs($u);

        return $u;
    }

    private function cedula(string $semilla7): string
    {
        $base = '17' . str_pad(substr($semilla7, 0, 7), 7, '0');
        $suma = 0;
        for ($i = 0; $i < 9; $i++) {
            $v = (int) $base[$i] * ($i % 2 === 0 ? 2 : 1);
            $suma += $v > 9 ? $v - 9 : $v;
        }

        return $base . ((10 - $suma % 10) % 10);
    }

    private function cedulaUnica(): string
    {
        return $this->cedula((string) random_int(1000000, 5999999));
    }

    private function datosCuenta(array $extra = []): array
    {
        return $extra + ['bank_id' => $this->banco->id, 'account_type' => 'AHORROS', 'account_number' => self::NUMERO,
            'holder_id_type' => 'C', 'holder_id' => $this->cedulaTitular, 'holder_name' => 'perez lopez juan'];
    }

    private function crearCuenta(?ThirdParty $t = null): ThirdPartyBankAccount
    {
        return ThirdPartyBankAccount::create($this->datosCuenta() + ['third_party_id' => ($t ?? $this->prod)->id]);
    }

    // 18/11/2026: cuenta VERIFICADA por Contabilidad (sin pasar por la ruta)
    private function verificar(ThirdPartyBankAccount $c): ThirdPartyBankAccount
    {
        $c->update(['verified_at' => now(), 'verified_by' => $this->usuario('CONTABILIDAD')->id]);

        return $c;
    }

    private function url(?ThirdParty $t = null): string
    {
        return '/api/producer-bank-accounts/' . ($t ?? $this->prod)->id;
    }

    // ───────────────────────── 1, 2, 3: crear cuenta ─────────────────────────

    public function test_liquidaciones_crea_cuenta_y_la_respuesta_va_enmascarada(): void
    {
        $this->como('LIQUIDACIONES');
        $r = $this->postJson($this->url(), $this->datosCuenta())->assertCreated();

        $this->assertSame('****4321', $r->json('numero'));
        $this->assertSame('PEREZ LOPEZ JUAN', $r->json('holder_name'));
        $this->assertStringNotContainsString(self::NUMERO, $r->getContent());
        $this->assertStringNotContainsString($this->cedulaTitular, $r->getContent());
        $this->assertSame(self::NUMERO, ThirdPartyBankAccount::where('third_party_id', $this->prod->id)->first()->account_number);

        // el GET también va enmascarado
        $g = $this->getJson($this->url())->assertOk();
        $this->assertTrue($g->json('tiene_cuenta'));
        $this->assertStringNotContainsString(self::NUMERO, $g->getContent());
        $this->assertStringNotContainsString($this->cedulaTitular, $g->getContent());
    }

    public function test_segunda_cuenta_da_422_ya_tiene_cuenta(): void
    {
        $this->como('LIQUIDACIONES');
        $this->postJson($this->url(), $this->datosCuenta())->assertCreated();
        $r = $this->postJson($this->url(), $this->datosCuenta(['account_number' => '1100000000099']))->assertStatus(422);

        $this->assertStringContainsString('YA TIENE CUENTA', $r->json('message'));
        $this->assertSame(1, ThirdPartyBankAccount::where('third_party_id', $this->prod->id)->count());
        $this->assertSame(self::NUMERO, ThirdPartyBankAccount::where('third_party_id', $this->prod->id)->first()->account_number);
    }

    public function test_tercero_proveedor_da_404(): void
    {
        $prov = ThirdParty::create(['name' => 'PROVEEDOR CB ' . uniqid(), 'type' => 'PROVEEDOR', 'status' => true]);
        $this->como('LIQUIDACIONES');

        $this->getJson($this->url($prov))->assertNotFound();
        $this->postJson($this->url($prov), $this->datosCuenta())->assertNotFound();
        $this->putJson('/api/producer-profiles/' . $prov->id, ['zone' => 'X'])->assertNotFound();
        $this->assertSame(0, ThirdPartyBankAccount::where('third_party_id', $prov->id)->count());
    }

    public function test_comercializadora_si_puede_tener_cuenta(): void
    {
        $com = ThirdParty::create(['name' => 'COMERCIALIZADORA CB ' . uniqid(), 'type' => 'COMERCIALIZADORA', 'status' => true]);
        $this->como('LIQUIDACIONES');
        $this->postJson($this->url($com), $this->datosCuenta())->assertCreated();
    }

    // ───────────────────────── 4: permisos ─────────────────────────

    public function test_usuario_sin_pantalla_recibe_403_en_get_post_y_put(): void
    {
        $this->como('BODEGUERO');

        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url(), $this->datosCuenta())->assertForbidden();
        $this->putJson('/api/producer-profiles/' . $this->prod->id, ['zone' => 'X'])->assertForbidden();
        $this->assertSame(0, ThirdPartyBankAccount::where('third_party_id', $this->prod->id)->count());
    }

    public function test_legal_no_ve_ni_crea_cuenta_ni_edita_ficha(): void
    {
        $this->como('LEGAL');

        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url(), $this->datosCuenta())->assertForbidden();
        $this->putJson('/api/producer-profiles/' . $this->prod->id, ['zone' => 'X'])->assertForbidden();
    }

    public function test_excepcion_por_persona_quita_la_ficha_pero_deja_la_cuenta(): void
    {
        Sanctum::actingAs($this->usuario('LIQUIDACIONES', ['screens_removed' => ['ficha_vendedores']]));

        $this->putJson('/api/producer-profiles/' . $this->prod->id, ['zone' => 'X'])->assertForbidden();
        $this->getJson($this->url())->assertOk();
    }

    public function test_admin_entra_a_todo(): void
    {
        $this->como('ADMIN');
        $this->getJson($this->url())->assertOk();
        $this->putJson('/api/producer-profiles/' . $this->prod->id, ['zone' => 'x'])->assertOk();
    }

    public function test_banks_lo_abre_liquidaciones_pero_no_legal(): void
    {
        $this->como('LIQUIDACIONES');
        $this->getJson('/api/accounting/banks')->assertOk();
        $this->como('LEGAL');
        $this->getJson('/api/accounting/banks')->assertForbidden();
    }

    // ───────────────────────── 5: bitácora ─────────────────────────

    public function test_bitacora_guarda_numero_y_titular_enmascarados(): void
    {
        $this->como('LIQUIDACIONES');
        $this->postJson($this->url(), $this->datosCuenta())->assertCreated();
        $cuenta = ThirdPartyBankAccount::where('third_party_id', $this->prod->id)->first();
        // un cambio posterior (lo haría Contabilidad) también debe quedar enmascarado
        $otraCedula = $this->cedula('2345678');
        $cuenta->update(['account_number' => '3300111122223333', 'holder_id' => $otraCedula]);

        $logs = AuditLog::where('table_name', 'third_party_bank_accounts')->where('record_id', $cuenta->id)->get();
        $this->assertGreaterThanOrEqual(2, $logs->count());
        $texto = $logs->map(fn ($l) => json_encode($l->toArray()))->implode('|');
        foreach ([self::NUMERO, '3300111122223333', $this->cedulaTitular, $otraCedula] as $completo) {
            $this->assertStringNotContainsString($completo, $texto, 'La bitácora guardó un dato completo');
        }
        $this->assertStringContainsString('4321', $texto);
    }

    // ───────────────────────── 6: Contabilidad ─────────────────────────

    public function test_contabilidad_ve_la_cuenta_completa_y_lista_al_productor(): void
    {
        $this->crearCuenta();
        $this->como('CONTABILIDAD');
        $r = $this->getJson('/api/accounting/bank-accounts')->assertOk();

        $fila = collect($r->json())->firstWhere('id', $this->prod->id);
        $this->assertNotNull($fila, 'El PRODUCTOR no aparece en Contabilidad');
        $this->assertSame(self::NUMERO, data_get($fila, 'bank_account.account_number'));
    }

    public function test_liquidaciones_no_entra_a_las_cuentas_de_contabilidad(): void
    {
        $this->crearCuenta();
        $this->como('LIQUIDACIONES');
        $this->getJson('/api/accounting/bank-accounts')->assertForbidden();
        $this->putJson('/api/accounting/bank-accounts/' . $this->prod->id, $this->datosCuenta())->assertForbidden();
    }

    public function test_contabilidad_sigue_pudiendo_corregir_la_cuenta(): void
    {
        $this->crearCuenta();
        $this->como('CONTABILIDAD');
        $this->putJson('/api/accounting/bank-accounts/' . $this->prod->id, $this->datosCuenta(['account_number' => '1100000000099']))->assertOk();
        $this->assertSame('1100000000099', ThirdPartyBankAccount::where('third_party_id', $this->prod->id)->first()->account_number);
    }

    // ───────────────────────── 7: /producer-contracts ─────────────────────────

    public function test_producer_contracts_manda_cuenta_enmascarada_a_liquidaciones_y_no_a_legal(): void
    {
        $this->crearCuenta();

        $this->como('LIQUIDACIONES');
        $r = $this->getJson('/api/producer-contracts')->assertOk();
        $fila = collect($r->json('terceros'))->firstWhere('id', $this->prod->id);
        $this->assertTrue($fila['cuenta']['tiene']);
        $this->assertSame('****4321', $fila['cuenta']['numero']);
        $this->assertStringNotContainsString(self::NUMERO, $r->getContent());
        $this->assertStringNotContainsString($this->cedulaTitular, $r->getContent());

        $this->como('LEGAL');
        $r = $this->getJson('/api/producer-contracts')->assertOk();
        $fila = collect($r->json('terceros'))->firstWhere('id', $this->prod->id);
        $this->assertNotNull($fila);
        $this->assertArrayNotHasKey('cuenta', $fila);
        $this->assertStringNotContainsString(self::NUMERO, $r->getContent());
    }

    public function test_producer_contracts_sin_cuenta_marca_tiene_false(): void
    {
        $this->como('LIQUIDACIONES');
        $fila = collect($this->getJson('/api/producer-contracts')->assertOk()->json('terceros'))->firstWhere('id', $this->prod->id);
        $this->assertFalse($fila['cuenta']['tiene']);
    }

    // ───────────────────────── 9: ficha ─────────────────────────

    public function test_ficha_completa_cedula_vacia_y_deja_mayusculas(): void
    {
        $this->como('LIQUIDACIONES');
        $ced = $this->cedulaUnica();
        $r = $this->putJson('/api/producer-profiles/' . $this->prod->id, [
            'identification' => $ced, 'zone' => 'machala', 'phone' => '0991112222', 'email' => 'Juan@Correo.COM', 'address' => 'calle uno y dos',
        ])->assertOk();

        $this->assertSame($ced, $r->json('identification'));
        $this->assertSame('MACHALA', $r->json('zone'));
        $this->assertSame('CALLE UNO Y DOS', $r->json('address'));
        $this->assertSame('juan@correo.com', $r->json('email'));
        $this->assertSame($ced, $this->prod->fresh()->identification);
    }

    public function test_ficha_sin_cedula_guarda_los_otros_datos(): void
    {
        $this->como('LIQUIDACIONES');
        $this->putJson('/api/producer-profiles/' . $this->prod->id, ['zone' => 'el oro'])->assertOk()->assertJsonPath('zone', 'EL ORO');
        $this->assertNull($this->prod->fresh()->identification);
    }

    public function test_ficha_cedula_invalida_da_422(): void
    {
        $this->como('LIQUIDACIONES');
        $buena = $this->cedulaUnica();
        $mala = substr($buena, 0, 9) . (((int) $buena[9] + 1) % 10);
        $r = $this->putJson('/api/producer-profiles/' . $this->prod->id, ['identification' => $mala])->assertStatus(422);

        $this->assertStringContainsString('NO VÁLIDO', $r->json('message'));
        $this->assertSame(mb_strtoupper($r->json('message')), $r->json('message'));
        $this->assertNull($this->prod->fresh()->identification);
    }

    public function test_ficha_no_cambia_una_cedula_ya_registrada(): void
    {
        $this->prod->update(['identification' => $this->cedulaUnica()]);
        $original = $this->prod->fresh()->identification;
        $this->como('LIQUIDACIONES');

        $r = $this->putJson('/api/producer-profiles/' . $this->prod->id, ['identification' => $this->cedulaUnica()])->assertStatus(422);
        $this->assertSame(mb_strtoupper($r->json('message')), $r->json('message'));
        $this->assertSame($original, $this->prod->fresh()->identification);

        // reenviar la misma cédula es válido
        $this->putJson('/api/producer-profiles/' . $this->prod->id, ['identification' => $original, 'zone' => 'x'])->assertOk();
    }

    public function test_ficha_cedula_repetida_de_otro_tercero_da_422(): void
    {
        $ced = $this->cedulaUnica();
        ThirdParty::create(['name' => 'OTRO PRODUCTOR ' . uniqid(), 'type' => 'PRODUCTOR', 'status' => true, 'identification' => $ced]);
        $this->como('LIQUIDACIONES');

        $r = $this->putJson('/api/producer-profiles/' . $this->prod->id, ['identification' => $ced])->assertStatus(422);
        $this->assertStringContainsString('YA ESTÁ REGISTRADA', $r->json('message'));
        $this->assertNull($this->prod->fresh()->identification);
    }

    public function test_ficha_email_invalido_da_422(): void
    {
        $this->como('LIQUIDACIONES');
        $r = $this->putJson('/api/producer-profiles/' . $this->prod->id, ['email' => 'esto-no-es-correo'])->assertStatus(422);
        $this->assertStringContainsString('CORREO', collect($r->json('errors'))->flatten()->implode(' '));
    }

    // 18/11/2026: un PUT parcial solo toca los campos que vienen en el request
    public function test_ficha_put_parcial_no_borra_los_otros_campos(): void
    {
        $this->prod->update(['zone' => 'MACHALA', 'phone' => '0991112222', 'email' => 'a@b.com', 'address' => 'CALLE UNO']);
        $this->como('LIQUIDACIONES');

        $this->putJson('/api/producer-profiles/' . $this->prod->id, ['zone' => 'el oro'])->assertOk();
        $t = $this->prod->fresh();
        $this->assertSame('EL ORO', $t->zone);
        $this->assertSame('0991112222', $t->phone);
        $this->assertSame('a@b.com', $t->email);
        $this->assertSame('CALLE UNO', $t->address);

        // enviar un campo vacío a propósito sí lo borra
        $this->putJson('/api/producer-profiles/' . $this->prod->id, ['phone' => ''])->assertOk();
        $this->assertNull($this->prod->fresh()->phone);
        $this->assertSame('EL ORO', $this->prod->fresh()->zone);
    }

    // 18/11/2026: LEGAL ya no recibe datos de contacto en /producer-contracts
    public function test_producer_contracts_no_manda_contacto_a_legal(): void
    {
        $this->prod->update(['zone' => 'MACHALA', 'phone' => '0991112222', 'email' => 'a@b.com', 'address' => 'CALLE UNO']);

        $this->como('LEGAL');
        $r = $this->getJson('/api/producer-contracts')->assertOk();
        $fila = collect($r->json('terceros'))->firstWhere('id', $this->prod->id);
        foreach (['zone', 'phone', 'email', 'address'] as $campo) {
            $this->assertArrayNotHasKey($campo, $fila, "LEGAL recibe $campo");
        }
        $this->assertStringNotContainsString('0991112222', $r->getContent());

        $this->como('LIQUIDACIONES');
        $fila = collect($this->getJson('/api/producer-contracts')->assertOk()->json('terceros'))->firstWhere('id', $this->prod->id);
        $this->assertSame('MACHALA', $fila['zone']);
        $this->assertSame('0991112222', $fila['phone']);
    }

    public function test_ficha_no_cambia_nombre_ni_tipo_ni_estado(): void
    {
        $this->como('LIQUIDACIONES');
        $nombre = $this->prod->name;
        $this->putJson('/api/producer-profiles/' . $this->prod->id, ['name' => 'OTRO', 'type' => 'CLIENTE', 'status' => false, 'zone' => 'x'])->assertOk();
        $t = $this->prod->fresh();
        $this->assertSame($nombre, $t->name);
        $this->assertSame('PRODUCTOR', $t->type);
        $this->assertTrue((bool) $t->status);
    }

    // ───────────────────────── 10: validaciones de la cuenta ─────────────────────────

    public function test_cuenta_con_cedula_de_titular_que_no_tiene_10_digitos_da_422(): void
    {
        $this->como('LIQUIDACIONES');
        $r = $this->postJson($this->url(), $this->datosCuenta(['holder_id' => '123456789']))->assertStatus(422);
        $this->assertStringContainsString('10 DÍGITOS', collect($r->json('errors'))->flatten()->implode(' '));
        $this->assertSame(0, ThirdPartyBankAccount::where('third_party_id', $this->prod->id)->count());
    }

    public function test_cuenta_con_ruc_que_no_tiene_13_digitos_da_422(): void
    {
        $this->como('LIQUIDACIONES');
        $this->postJson($this->url(), $this->datosCuenta(['holder_id_type' => 'R', 'holder_id' => $this->cedulaTitular]))->assertStatus(422);
    }

    public function test_cuenta_con_numero_con_letras_da_422_en_mayusculas(): void
    {
        $this->como('LIQUIDACIONES');
        $r = $this->postJson($this->url(), $this->datosCuenta(['account_number' => '22009ABC']))->assertStatus(422);
        $msg = collect($r->json('errors'))->flatten()->implode(' ');
        $this->assertStringContainsString('SOLO DÍGITOS', $msg);
        $this->assertSame(mb_strtoupper($msg), $msg);
    }

    public function test_cuenta_con_banco_desactivado_da_422(): void
    {
        $this->banco->update(['status' => false]);
        $this->como('LIQUIDACIONES');
        $this->postJson($this->url(), $this->datosCuenta())->assertStatus(422);
    }

    // ───────────────────────── 8: pay() y detalle ─────────────────────────

    /** Arma una liquidación mínima (1 LQ SPOT de 800 cajas) del productor de la prueba. */
    private function liquidacion(): Settlement
    {
        $k = self::$n . '-' . strtoupper(substr(uniqid(), -6));
        $admin = $this->usuario('ADMIN');
        $bodeguero = $this->usuario('BODEGUERO');
        $bodega = Warehouse::create(['name' => "BODEGA CB$k", 'code' => "CB$k", 'responsible_user_id' => $bodeguero->id, 'status' => true]);
        $puerto = Port::create(['name' => "PUERTO CB$k", 'port_type' => 'SALIDA', 'status' => true]);
        $finca = Farm::create(['third_party_id' => $this->prod->id, 'name' => "FINCA CB$k", 'magap_code' => 'C-0001', 'status' => true]);
        $cliT = ThirdParty::create(['name' => "CLIENTE CB$k", 'type' => 'CLIENTE', 'status' => true]);
        $cli = Customer::create(['customer_code' => "CUST-CB$k", 'third_party_id' => $cliT->id, 'country' => 'RUSIA', 'negotiation_type' => 'CONTRATO', 'status' => true]);
        $marca = Brand::create(['name' => "MARCA CB$k", 'code' => "MC$k", 'status' => true]);
        $cli->brands()->attach($marca->id);
        $pedido = CustomerOrder::create(['week' => 46, 'year' => 2026, 'expected_boxes' => 20000, 'status' => true]);
        CustomerOrderLine::create(['customer_order_id' => $pedido->id, 'customer_id' => $cli->id, 'brand_id' => $marca->id,
            'presentation' => 'CONVENCIONAL', 'boxes' => 20000, 'incoterm' => 'FOB', 'cargo_type' => 'CAJA_BAJO_CUBIERTA', 'negotiation_type' => 'CONTRATO']);
        $naviera = ShippingLine::create(['name' => "NAVIERA CB$k"]);
        $barco = Shipment::create(['code' => "SC$k", 'registration_date' => '2026-11-09', 'departure_date' => '2026-11-20', 'vessel_name' => "NAVE CB$k",
            'shipping_line_id' => $naviera->id, 'week_start' => 46, 'week_end' => 46, 'year' => 2026, 'customer_order_id' => $pedido->id, 'status' => true]);
        SettlementWeekParameter::firstOrCreate(['year' => 2026, 'week' => 46], ['minimum_price' => 6.0, 'bpa_rate' => 0.01]);
        if (DB::table('retention_brackets')->count() === 0) {
            foreach ([[0, 1000, 0.01], [1000, null, 0.0125]] as [$d, $h, $t]) {
                DB::table('retention_brackets')->insert(['from_boxes' => $d, 'to_boxes' => $h, 'rate' => $t, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        Sanctum::actingAs($admin);
        $url = "/api/customer-orders/{$pedido->id}/purchases";
        $version = $this->getJson($url)->assertOk()->json('version');
        $bloque = PurchaseBlock::where('customer_order_id', $pedido->id)->where('brand_id', $marca->id)->value('id');
        $this->putJson($url, ['version' => $version, 'blocks' => [[
            'id' => $bloque, 'shipment_id' => $barco->id, 'brand_id' => $marca->id, 'presentation' => 'CONVENCIONAL',
            'customer_id' => $cli->id, 'cargo_mode' => 'CAJA_BAJO_CUBIERTA', 'target_boxes' => 20000,
            'purchases' => [['third_party_id' => $this->prod->id, 'farm_id' => $finca->id, 'boxes' => 800, 'official_price' => 7.0, 'real_price' => 6.0,
                'negotiation_type' => 'SPOT', 'payment_scheme' => '100', 'pickup_warehouse_id' => $bodega->id, 'delivery_port_id' => $puerto->id]],
        ]]])->assertOk();
        $bloque = PurchaseBlock::where('customer_order_id', $pedido->id)->where('brand_id', $marca->id)->value('id');
        $linea = ProducerQuotaLine::where('purchase_block_id', $bloque)->orderBy('id')->first();
        TallySheet::create(['producer_quota_line_id' => $linea->id, 'shipment_id' => $barco->id, 'date' => '2026-11-12',
            'boxes_shipped' => 800, 'boxes_fallen' => 0, 'boxes_rejected' => 0, 'boxes_missing' => 0]);
        $this->postJson('/api/settlements', ['producer_quota_id' => $linea->producer_quota_id, 'date' => '2026-11-13', 'advances' => []])->assertStatus(201);

        return Settlement::where('producer_quota_id', $linea->producer_quota_id)->where('status', true)->firstOrFail();
    }

    // 18/11/2026: el bloqueo se sube apagado (config/liquidacion.php); con él apagado el pago se registra igual
    public function test_pay_sin_cuenta_ni_cedula_registra_el_pago_con_el_bloqueo_apagado(): void
    {
        config(['liquidacion.bloquear_pago_sin_datos' => false]);
        $lq = $this->liquidacion();

        $this->patchJson("/api/settlements/{$lq->id}/payments/1/pay", ['paid_at' => '2026-11-14'])->assertOk();
        $this->assertNotNull($lq->payments()->where('installment', 1)->first()->paid_at);
    }

    public function test_pay_sin_cuenta_da_422_y_no_registra_el_pago(): void
    {
        config(['liquidacion.bloquear_pago_sin_datos' => true]);
        $this->prod->update(['identification' => $this->cedulaUnica()]);
        $lq = $this->liquidacion();

        $r = $this->patchJson("/api/settlements/{$lq->id}/payments/1/pay", ['paid_at' => '2026-11-14'])->assertStatus(422);
        $this->assertStringContainsString('CUENTA BANCARIA', $r->json('message'));
        $this->assertNull($lq->payments()->where('installment', 1)->first()->paid_at);
    }

    public function test_pay_con_cuenta_pero_sin_cedula_da_422(): void
    {
        config(['liquidacion.bloquear_pago_sin_datos' => true]);
        $this->verificar($this->crearCuenta());
        $lq = $this->liquidacion();

        $r = $this->patchJson("/api/settlements/{$lq->id}/payments/1/pay", ['paid_at' => '2026-11-14'])->assertStatus(422);
        $this->assertStringContainsString('CÉDULA', $r->json('message'));
        $this->assertNull($lq->payments()->where('installment', 1)->first()->paid_at);
    }

    // ───────────────────────── 18/11/2026: PENDIENTE / VERIFICADA ─────────────────────────

    public function test_cuenta_que_crea_liquidaciones_nace_pendiente(): void
    {
        $this->como('LIQUIDACIONES');
        $r = $this->postJson($this->url(), $this->datosCuenta())->assertStatus(201);
        $this->assertFalse($r->json('verificada'));
        $this->assertNull(ThirdPartyBankAccount::where('third_party_id', $this->prod->id)->first()->verified_at);

        $fila = collect($this->getJson('/api/producer-contracts')->assertOk()->json('terceros'))->firstWhere('id', $this->prod->id);
        $this->assertFalse(data_get($fila, 'cuenta.verificada'));
    }

    public function test_contabilidad_verifica_y_queda_quien_y_cuando(): void
    {
        $this->crearCuenta();
        $conta = $this->como('CONTABILIDAD');
        $this->patchJson('/api/accounting/bank-accounts/' . $this->prod->id . '/verify')->assertOk()
            ->assertJsonPath('verified_by', $conta->id);
        $this->assertNotNull(ThirdPartyBankAccount::where('third_party_id', $this->prod->id)->first()->verified_at);

        // otra vez → 422
        $this->patchJson('/api/accounting/bank-accounts/' . $this->prod->id . '/verify')->assertStatus(422);

        // Liquidaciones la ve VERIFICADA
        $this->como('LIQUIDACIONES');
        $this->getJson($this->url())->assertOk()->assertJsonPath('verificada', true);
    }

    public function test_cambiar_numero_la_devuelve_a_pendiente_y_el_correo_no(): void
    {
        // el titular ya en MAYÚSCULAS, como lo guarda la pantalla (si no, el PUT lo cambia y cuenta como cambio)
        $this->verificar(ThirdPartyBankAccount::create($this->datosCuenta(['holder_name' => 'PEREZ LOPEZ JUAN']) + ['third_party_id' => $this->prod->id]));
        $this->como('CONTABILIDAD');

        // solo el correo: sigue VERIFICADA (el número cifrado se compara descifrado, no cuenta como cambio)
        $this->putJson('/api/accounting/bank-accounts/' . $this->prod->id, $this->datosCuenta(['email' => 'pagos@ejemplo.com']))->assertOk();
        $this->assertNotNull(ThirdPartyBankAccount::where('third_party_id', $this->prod->id)->first()->verified_at);

        // el número: vuelve a PENDIENTE
        $this->putJson('/api/accounting/bank-accounts/' . $this->prod->id, $this->datosCuenta(['account_number' => '1100000000099']))->assertOk();
        $c = ThirdPartyBankAccount::where('third_party_id', $this->prod->id)->first();
        $this->assertNull($c->verified_at);
        $this->assertNull($c->verified_by);
    }

    public function test_verificar_requiere_pantalla_de_contabilidad(): void
    {
        $this->crearCuenta();
        $this->como('LIQUIDACIONES');
        $this->patchJson('/api/accounting/bank-accounts/' . $this->prod->id . '/verify')->assertStatus(403);
        $this->assertNull(ThirdPartyBankAccount::where('third_party_id', $this->prod->id)->first()->verified_at);
    }

    public function test_con_bloqueo_la_cuenta_pendiente_no_deja_pagar(): void
    {
        config(['liquidacion.bloquear_pago_sin_datos' => true]);
        $this->crearCuenta();
        $this->prod->update(['identification' => $this->cedulaUnica()]);
        $lq = $this->liquidacion();

        $r = $this->patchJson("/api/settlements/{$lq->id}/payments/1/pay", ['paid_at' => '2026-11-14'])->assertStatus(422);
        $this->assertStringContainsString('PENDIENTE', $r->json('message'));
        $this->assertNull($lq->payments()->where('installment', 1)->first()->paid_at);
    }

    public function test_pay_con_cuenta_y_cedula_registra_el_pago(): void
    {
        config(['liquidacion.bloquear_pago_sin_datos' => true]);
        $this->verificar($this->crearCuenta());
        $this->prod->update(['identification' => $this->cedulaUnica()]);
        $lq = $this->liquidacion();

        $this->patchJson("/api/settlements/{$lq->id}/payments/1/pay", ['paid_at' => '2026-11-14'])->assertOk();
        $this->assertNotNull($lq->payments()->where('installment', 1)->first()->paid_at);
    }

    public function test_detalle_y_preview_traen_cuenta_enmascarada_y_aviso_sin_cedula(): void
    {
        $this->crearCuenta();
        $lq = $this->liquidacion();   // el productor no tiene cédula

        $d = $this->getJson("/api/settlements/{$lq->id}")->assertOk();
        $this->assertTrue($d->json('cuenta_vendedor.tiene_cuenta'));
        $this->assertSame('****4321', $d->json('cuenta_vendedor.numero'));
        $this->assertStringNotContainsString(self::NUMERO, $d->getContent());
        $this->assertStringNotContainsString($this->cedulaTitular, $d->getContent());

        $p = $this->getJson("/api/settlements/preview/{$lq->producer_quota_id}")->assertOk();
        $this->assertSame('****4321', $p->json('cuenta_vendedor.numero'));
        $this->assertStringNotContainsString(self::NUMERO, $p->getContent());
        $this->assertStringNotContainsString($this->cedulaTitular, $p->getContent());
        $this->assertStringContainsString('NO TIENE CÉDULA', implode(' ', $p->json('avisos')));
    }

    public function test_detalle_sin_cuenta_marca_tiene_cuenta_false(): void
    {
        $lq = $this->liquidacion();
        $this->assertFalse($this->getJson("/api/settlements/{$lq->id}")->assertOk()->json('cuenta_vendedor.tiene_cuenta'));
    }
}
