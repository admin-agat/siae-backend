<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 31/10/2026: el bodeguero de la bodega destino confirma las transferencias que le llegan.
// Se agrega la pantalla nueva "recibir_transferencias" a los perfiles de tipo BODEGUERO.
return new class extends Migration
{
    private const PANTALLA = 'recibir_transferencias';

    public function up(): void
    {
        $this->cambiar(fn (array $p) => in_array(self::PANTALLA, $p) ? $p : [...$p, self::PANTALLA]);
    }

    public function down(): void
    {
        $this->cambiar(fn (array $p) => array_values(array_diff($p, [self::PANTALLA])));
    }

    private function cambiar(callable $fn): void
    {
        foreach (DB::table('profiles')->where('base_role', 'BODEGUERO')->get(['id', 'screens']) as $perfil) {
            $pantallas = json_decode($perfil->screens ?? '[]', true) ?: [];
            DB::table('profiles')->where('id', $perfil->id)->update(['screens' => json_encode($fn($pantallas))]);
        }
    }
};
