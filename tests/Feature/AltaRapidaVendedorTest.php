<?php

// 18/11/2026: POST /api/customer-orders/{id}/purchases/third-parties (PurchaseAllocationController::crearVendedor).
// Base: siae_test con DatabaseTransactions; cada prueba crea sus datos.

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CustomerOrder;
use App\Models\ThirdParty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AltaRapidaVendedorTest extends TestCase
{
    use DatabaseTransactions;

    private CustomerOrder $pedido;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('siae_test', \DB::connection()->getDatabaseName());
        $this->pedido = CustomerOrder::create(['week' => 46, 'year' => 2026, 'expected_boxes' => 1000, 'status' => true]);
        Sanctum::actingAs($this->usuario('COMERCIAL'));
    }

    private function usuario(string $rol): User
    {
        return User::create(['name' => "USUARIO $rol", 'email' => strtolower($rol) . uniqid() . '@test.local', 'password' => 'Clave-Test-123',
            'role' => $rol, 'status' => true, 'must_change_password' => false, 'password_changed_at' => now()]);
    }

    /** Palabra aleatoria de 7 letras (evita falsos "parecidos" con datos de otras pruebas). */
    private function palabra(): string
    {
        $s = '';
        for ($i = 0; $i < 7; $i++) {
            $s .= chr(random_int(65, 90));
        }
        return $s;
    }

    private function nombre(): string
    {
        return $this->palabra() . ' ' . $this->palabra();
    }

    private function cedula(): string
    {
        $base = '17' . str_pad((string) random_int(1000000, 5999999), 7, '0');
        $suma = 0;
        for ($i = 0; $i < 9; $i++) {
            $v = (int) $base[$i] * ($i % 2 === 0 ? 2 : 1);
            $suma += $v > 9 ? $v - 9 : $v;
        }
        return $base . ((10 - $suma % 10) % 10);
    }

    private function alta(array $extra = [])
    {
        return $this->postJson("/api/customer-orders/{$this->pedido->id}/purchases/third-parties",
            $extra + ['type' => 'PRODUCTOR', 'name' => $this->nombre()]);
    }

    // ───────── Validación ─────────

    public function test_tipo_obligatorio_y_solo_productor_o_comercializadora(): void
    {
        $this->alta(['type' => null])->assertStatus(422)->assertJsonPath('errors.type.0', 'ELIGE SI ES PRODUCTOR O COMERCIALIZADORA.');
        foreach (['CLIENTE', 'PROVEEDOR'] as $t) {
            $this->alta(['type' => $t])->assertStatus(422)->assertJsonPath('errors.type.0', 'ELIGE SI ES PRODUCTOR O COMERCIALIZADORA.');
        }
    }

    public function test_nombre_minimo_5_y_maximo_255(): void
    {
        $this->alta(['name' => 'ABCD'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->alta(['name' => str_repeat('A', 256)])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_productor_con_una_sola_palabra_se_rechaza_pero_comercializadora_si_pasa(): void
    {
        $uno = $this->palabra();
        $this->alta(['name' => $uno])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->alta(['name' => $uno, 'type' => 'COMERCIALIZADORA'])->assertCreated();
    }

    public function test_nombre_en_mayusculas_y_sin_espacios_dobles(): void
    {
        $a = strtolower($this->palabra());
        $b = strtolower($this->palabra());
        $r = $this->alta(['name' => "  $a    $b  "])->assertCreated();
        $this->assertSame(strtoupper("$a $b"), $r->json('name'));
    }

    public function test_sin_identificacion_se_crea_con_null(): void
    {
        $r = $this->alta()->assertCreated();
        $this->assertNull($r->json('identification'));
        $this->assertNull(ThirdParty::find($r->json('id'))->identification);
    }

    public function test_identificacion_invalida_da_error_en_identification(): void
    {
        $buena = $this->cedula();
        $mala = substr($buena, 0, 9) . (((int) $buena[9] + 1) % 10);
        $r = $this->alta(['identification' => $mala])->assertStatus(422);
        $this->assertStringStartsWith('CÉDULA O RUC NO VÁLIDO', $r->json('errors.identification.0'));
        $this->alta(['identification' => '12345'])->assertStatus(422)->assertJsonValidationErrors('identification');
    }

    public function test_identificacion_con_guiones_se_limpia_a_digitos(): void
    {
        $c = $this->cedula();
        $r = $this->alta(['identification' => substr($c, 0, 5) . '-' . substr($c, 5)])->assertCreated();
        $this->assertSame($c, $r->json('identification'));
    }

    // ───────── Duplicados ─────────

    public function test_identificacion_repetida_devuelve_existente(): void
    {
        $c = $this->cedula();
        $e = $this->alta(['identification' => $c])->assertCreated();
        $r = $this->alta(['identification' => $c])->assertStatus(422);
        $r->assertJsonValidationErrors('identification');
        $this->assertSame($e->json('id'), $r->json('existente.id'));
        foreach (['id', 'name', 'type', 'status'] as $k) {
            $this->assertArrayHasKey($k, $r->json('existente'));
        }
    }

    public function test_cedula_dentro_de_un_ruc_existente_se_detecta(): void
    {
        $c = $this->cedula();
        $e = $this->alta(['type' => 'COMERCIALIZADORA', 'identification' => $c . '001'])->assertCreated();
        $r = $this->alta(['identification' => $c])->assertStatus(422);
        $this->assertSame($e->json('id'), $r->json('existente.id'));
    }

    public function test_ruc_nuevo_con_cedula_ya_registrada_se_detecta(): void
    {
        $c = $this->cedula();
        $e = $this->alta(['identification' => $c])->assertCreated();
        $r = $this->alta(['type' => 'COMERCIALIZADORA', 'identification' => $c . '001'])->assertStatus(422);
        $this->assertSame($e->json('id'), $r->json('existente.id'));
    }

    public function test_identificacion_de_tercero_de_otro_tipo_tambien_choca(): void
    {
        $c = $this->cedula();
        $cli = ThirdParty::create(['name' => $this->nombre(), 'type' => 'CLIENTE', 'identification' => $c, 'status' => true]);
        $r = $this->alta(['identification' => $c])->assertStatus(422);
        $this->assertSame($cli->id, $r->json('existente.id'));
    }

    public function test_identificacion_de_tercero_inactivo_pide_reactivar(): void
    {
        $c = $this->cedula();
        ThirdParty::create(['name' => $this->nombre(), 'type' => 'PRODUCTOR', 'identification' => $c, 'status' => false]);
        $r = $this->alta(['identification' => $c])->assertStatus(422);
        $this->assertStringContainsString('PÍDELE AL ADMIN QUE LO REACTIVE', $r->json('errors.identification.0'));
        $this->assertFalse((bool) $r->json('existente.status'));
    }

    public function test_mismo_nombre_y_tipo_devuelve_existente(): void
    {
        $n = $this->nombre();
        $e = $this->alta(['name' => $n])->assertCreated();
        $r = $this->alta(['name' => strtolower($n)])->assertStatus(422);
        $r->assertJsonValidationErrors('name');
        $this->assertSame($e->json('id'), $r->json('existente.id'));
    }

    public function test_mismo_nombre_inactivo_pide_reactivar(): void
    {
        $n = $this->nombre();
        ThirdParty::create(['name' => $n, 'type' => 'PRODUCTOR', 'status' => false]);
        $r = $this->alta(['name' => $n])->assertStatus(422);
        $this->assertStringContainsString('PÍDELE AL ADMIN QUE LO REACTIVE', $r->json('errors.name.0'));
    }

    // ───────── Parecidos ─────────

    private function existente(string $nombre, string $type = 'PRODUCTOR', ?string $ident = null): ThirdParty
    {
        return ThirdParty::create(['name' => $nombre, 'type' => $type, 'identification' => $ident, 'status' => true]);
    }

    public function test_subconjunto_de_palabras_con_dos_comunes_da_409(): void
    {
        [$a, $b, $c, $d] = [$this->palabra(), $this->palabra(), $this->palabra(), $this->palabra()];
        $e = $this->existente("$a $b $c $d");
        $r = $this->alta(['name' => "$a $c"])->assertStatus(409);
        $this->assertSame('PARECIDOS', $r->json('codigo'));
        $this->assertSame($e->id, $r->json('parecidos.0.id'));
    }

    public function test_mismas_palabras_en_otro_orden_da_409(): void
    {
        [$a, $b] = [$this->palabra(), $this->palabra()];
        $this->existente("$a $b");
        $this->alta(['name' => "$b $a"])->assertStatus(409)->assertJsonPath('codigo', 'PARECIDOS');
    }

    public function test_error_de_tipeo_levenshtein_da_409(): void
    {
        $a = $this->palabra();
        $b = $this->palabra();
        $this->existente("$a $b");
        $typo = substr($a, 0, 6) . (substr($a, 6, 1) === 'Q' ? 'W' : 'Q');
        $this->alta(['name' => "$typo $b"])->assertStatus(409)->assertJsonPath('codigo', 'PARECIDOS');
    }

    public function test_palabra_larga_contenida_en_otro_nombre_da_409(): void
    {
        $sigla = $this->palabra();
        $this->existente('ASO' . $sigla . 'XY', 'COMERCIALIZADORA');
        $this->alta(['name' => $sigla . ' ' . $this->palabra()])->assertStatus(409);
    }

    public function test_nombres_distintos_no_dan_parecidos(): void
    {
        $this->existente('AAAAAAA BBBBBBB');
        $this->alta(['name' => 'ZZZZZZZ YYYYYYY'])->assertCreated();
    }

    public function test_confirmar_parecidos_crea_201(): void
    {
        [$a, $b] = [$this->palabra(), $this->palabra()];
        $this->existente("$a $b");
        $this->alta(['name' => "$b $a", 'confirmar_parecidos' => true])->assertCreated();
        $this->assertSame(2, ThirdParty::whereRaw('UPPER(name) in (?, ?)', ["$a $b", "$b $a"])->count());
    }

    public function test_parecidos_maximo_5_con_identificacion_enmascarada(): void
    {
        [$a, $b] = [$this->palabra(), $this->palabra()];
        $c = $this->cedula();
        $this->existente("$a $b EXTRA0", 'PRODUCTOR', $c);
        for ($i = 1; $i <= 6; $i++) {
            $this->existente("$a $b EXTRA$i");
        }
        $r = $this->alta(['name' => "$a $b"])->assertStatus(409);
        $lista = $r->json('parecidos');
        $this->assertCount(5, $lista);
        foreach ($lista as $p) {
            foreach (['id', 'name', 'type', 'status', 'identificacion'] as $k) {
                $this->assertArrayHasKey($k, $p);
            }
            $this->assertArrayNotHasKey('puntos', $p);
            if ($p['identificacion'] !== null) {
                $this->assertSame(str_repeat('#', 6) . substr($c, -4), $p['identificacion']);
            }
        }
    }

    public function test_parecido_sin_identificacion_la_devuelve_null(): void
    {
        [$a, $b] = [$this->palabra(), $this->palabra()];
        $this->existente("$a $b");
        $this->assertNull($this->alta(['name' => "$b $a"])->assertStatus(409)->json('parecidos.0.identificacion'));
    }

    // ───────── Éxito, bitácora y permisos ─────────

    public function test_201_devuelve_campos_y_deja_bitacora(): void
    {
        $c = $this->cedula();
        $r = $this->alta(['identification' => $c])->assertCreated();
        foreach (['id', 'name', 'type', 'status', 'identification'] as $k) {
            $this->assertArrayHasKey($k, $r->json());
        }
        $this->assertTrue((bool) $r->json('status'));
        $this->assertSame('PRODUCTOR', $r->json('type'));
        $log = AuditLog::where('table_name', 'third_parties')->where('record_id', $r->json('id'))->first();
        $this->assertNotNull($log);
        $this->assertSame('CREAR', $log->action);
    }

    public function test_pedido_inexistente_da_404(): void
    {
        $this->postJson('/api/customer-orders/99999999/purchases/third-parties', ['type' => 'PRODUCTOR', 'name' => $this->nombre()])->assertStatus(404);
    }

    public function test_roles_sin_pantalla_compra_fruta_reciben_403(): void
    {
        foreach (['BODEGUERO', 'TARJADOR', 'LIQUIDACIONES'] as $rol) {
            Sanctum::actingAs($this->usuario($rol));
            $this->alta()->assertStatus(403);
        }
    }

    public function test_comercial_y_admin_pueden_crear(): void
    {
        $this->alta()->assertCreated();
        Sanctum::actingAs($this->usuario('ADMIN'));
        $this->alta()->assertCreated();
    }

    public function test_sin_sesion_da_401(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->postJson("/api/customer-orders/{$this->pedido->id}/purchases/third-parties", ['type' => 'PRODUCTOR', 'name' => 'A B CDEF'])->assertStatus(401);
    }
}
