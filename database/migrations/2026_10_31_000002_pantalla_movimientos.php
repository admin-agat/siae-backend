<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 31/10/2026: pantalla nueva "Movimientos y kárdex" para los perfiles de inventario
// (el BODEGUERO solo ve su bodega; corregir y anular quedan para COORDINADOR_INVENTARIO y ADMIN).
return new class extends Migration
{
    private const PANTALLA = 'movimientos';
    private const ROLES = ['COORDINADOR_INVENTARIO', 'JEFE_BODEGA', 'BODEGUERO'];

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
        foreach (DB::table('profiles')->whereIn('base_role', self::ROLES)->get(['id', 'screens']) as $perfil) {
            $pantallas = json_decode($perfil->screens ?? '[]', true) ?: [];
            DB::table('profiles')->where('id', $perfil->id)->update(['screens' => json_encode($fn($pantallas))]);
        }
    }
};
