<?php

// 18/11/2026: comportamiento ACTUAL de POST /api/third-parties (ThirdPartyController::store) respecto de la
// identificación, antes de agregar el alta rápida de vendedor desde Compra de fruta.
// Hallazgo: store YA valida cédula/RUC (IdentificacionEcuador: módulo 10 en cédula, estructura en RUC) y rechaza duplicados
// por identificación y por nombre+tipo, solo para PRODUCTOR y COMERCIALIZADORA. Las únicas pruebas "skip" son lo que aún no hay.
// Base: siae_test con DatabaseTransactions.

namespace Tests\Feature;

use App\Models\ThirdParty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TerceroIdentificacionTest extends TestCase
{
    use DatabaseTransactions;

    private static int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('siae_test', \DB::connection()->getDatabaseName());
        ++self::$n;
        Sanctum::actingAs($this->usuario('ADMIN'));
    }

    private function usuario(string $rol): User
    {
        return User::create(['name' => "USUARIO $rol", 'email' => strtolower($rol) . self::$n . uniqid() . '@test.local', 'password' => 'Clave-Test-123',
            'role' => $rol, 'status' => true, 'must_change_password' => false, 'password_changed_at' => now()]);
    }

    /** Cédula de prueba con dígito verificador correcto (módulo 10), provincia 17. */
    private function cedula(string $semilla8): string
    {
        $base = '17' . str_pad(substr($semilla8, 0, 7), 7, '0');   // 9 dígitos, tercer dígito < 6
        $suma = 0;
        for ($i = 0; $i < 9; $i++) {
            $v = (int) $base[$i] * ($i % 2 === 0 ? 2 : 1);
            $suma += $v > 9 ? $v - 9 : $v;
        }
        return $base . ((10 - $suma % 10) % 10);
    }

    /** Cédula única por prueba (evita chocar con otras ejecuciones). */
    private function cedulaUnica(): string
    {
        return $this->cedula((string) random_int(1000000, 5999999));
    }

    private function alta(array $extra = [])
    {
        return $this->postJson('/api/third-parties', $extra + ['name' => 'VENDEDOR PRUEBA ' . uniqid(), 'type' => 'PRODUCTOR', 'identification' => $this->cedulaUnica()]);
    }

    // ───────────────────────── Comportamiento actual ─────────────────────────

    public function test_crea_productor_con_cedula_valida_y_la_deja_en_mayusculas(): void
    {
        $r = $this->alta(['name' => 'perez  lopez juan ' . uniqid()])->assertCreated();
        $this->assertSame(mb_strtoupper($r->json('name')), $r->json('name'));
        $this->assertStringNotContainsString('  ', $r->json('name'));
        $this->assertTrue((bool) ThirdParty::find($r->json('id'))->status);
    }

    public function test_crea_comercializadora_con_ruc_de_13_digitos_de_persona_natural(): void
    {
        $this->alta(['type' => 'COMERCIALIZADORA', 'identification' => $this->cedulaUnica() . '001'])->assertCreated();
    }

    public function test_productor_sin_identificacion_se_rechaza(): void
    {
        $this->alta(['identification' => null])->assertStatus(422)
            ->assertJsonPath('errors.identification.0', 'LA CÉDULA O RUC ES OBLIGATORIA PARA UN PRODUCTOR O COMERCIALIZADORA.');
    }

    public function test_cedula_con_digito_verificador_malo_se_rechaza(): void
    {
        $buena = $this->cedulaUnica();
        $mala = substr($buena, 0, 9) . (((int) $buena[9] + 1) % 10);
        $r = $this->alta(['identification' => $mala])->assertStatus(422);
        $this->assertStringContainsString('CÉDULA O RUC NO VÁLIDO', $r->json('message'));
        $this->assertSame(0, ThirdParty::where('identification', $mala)->count());
    }

    public function test_identificacion_con_largo_o_letras_incorrectos_se_rechaza(): void
    {
        foreach (['12345', '17ABCDEFGH', '170000000012'] as $malo) {
            $this->alta(['identification' => $malo])->assertStatus(422);
        }
    }

    public function test_provincia_invalida_se_rechaza(): void
    {
        $this->alta(['identification' => '7042007323001'])->assertStatus(422);
    }

    public function test_misma_identificacion_en_otro_nombre_se_rechaza(): void
    {
        $id = $this->cedulaUnica();
        $this->alta(['identification' => $id])->assertCreated();
        $r = $this->alta(['identification' => $id])->assertStatus(422);
        $this->assertStringContainsString('YA ESTÁ REGISTRADA', $r->json('message'));
        $this->assertSame(1, ThirdParty::where('identification', $id)->count());
    }

    public function test_misma_identificacion_con_otro_tipo_tambien_se_rechaza(): void
    {
        $id = $this->cedulaUnica();
        $this->alta(['identification' => $id, 'type' => 'PRODUCTOR'])->assertCreated();
        $this->alta(['identification' => $id, 'type' => 'COMERCIALIZADORA'])->assertStatus(422);
    }

    public function test_mismo_nombre_y_tipo_se_rechaza_sin_importar_mayusculas(): void
    {
        $nombre = 'REPETIDO ' . uniqid();
        $this->alta(['name' => $nombre])->assertCreated();
        $r = $this->alta(['name' => strtolower($nombre)])->assertStatus(422);
        $this->assertStringContainsString('YA EXISTE UN PRODUCTOR', $r->json('message'));
    }

    public function test_proveedor_no_exige_identificacion_ni_valida_formato(): void
    {
        // Documenta: la validación de cédula/RUC solo aplica a PRODUCTOR y COMERCIALIZADORA.
        $this->alta(['type' => 'PROVEEDOR', 'identification' => null])->assertCreated();
        $this->alta(['type' => 'PROVEEDOR', 'identification' => 'ABC-123'])->assertCreated();
    }

    public function test_ruc_de_sociedad_solo_revisa_estructura_no_modulo_11(): void
    {
        // Documenta (decisión del 03/11/2026): RUC de sociedad con tercer dígito 9 pasa sin verificar módulo 11.
        $this->alta(['type' => 'COMERCIALIZADORA', 'identification' => '1791234567001'])->assertCreated();
    }

    public function test_el_vendedor_nuevo_queda_activo_y_listo_para_comprarle(): void
    {
        $r = $this->alta()->assertCreated();
        $this->assertTrue((bool) ThirdParty::find($r->json('id'))->status);
        $this->getJson('/api/third-parties?status=1&type=PRODUCTOR')->assertOk()->assertJsonFragment(['id' => $r->json('id')]);
    }

    public function test_crear_tercero_deja_bitacora(): void
    {
        $r = $this->alta()->assertCreated();
        $this->assertTrue(\App\Models\AuditLog::where('table_name', 'third_parties')->where('record_id', $r->json('id'))->exists());
    }

    // ───────────────────────── Permisos (lo que importa para el alta rápida) ─────────────────────────

    public function test_comercial_sin_pantalla_terceros_no_puede_crear_vendedor(): void
    {
        // HOY: POST /third-parties exige pantalla terceros o fincas (middleware). COMERCIAL no la tiene.
        // El alta rápida desde Compra de fruta necesitará su propia ruta con pantalla:compra_fruta.
        $c = $this->usuario('COMERCIAL');
        Sanctum::actingAs($c);
        $this->alta()->assertStatus(403);
    }

    public function test_bodeguero_no_puede_crear_terceros(): void
    {
        Sanctum::actingAs($this->usuario('BODEGUERO'));
        $this->alta()->assertStatus(403);
    }

    // ───────────────────────── Lo deseado que todavía no existe ─────────────────────────

    public function test_deseado_duplicado_por_identificacion_trae_errors_identification(): void
    {
        $this->markTestSkipped('PENDIENTE: el duplicado hoy responde solo {message}; el frontend del alta rápida preferiría errors.identification como en cédula inválida.');
    }

    public function test_deseado_comercial_crea_vendedor_desde_compra_de_fruta_con_pantalla_compra_fruta(): void
    {
        $this->markTestSkipped('PENDIENTE: ruta de alta rápida con pantalla:compra_fruta (hoy no existe; /third-parties pide pantalla terceros).');
    }
}
