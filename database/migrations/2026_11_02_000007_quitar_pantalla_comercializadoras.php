<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 02/11/2026: se quitó la pantalla Comercializadoras (era Terceros filtrado a ese tipo; ahora Terceros
// tiene un filtro por tipo). Quien la tenía en su perfil o como excepción pasa a tener Terceros.
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('profiles')->get(['id', 'screens']) as $p) {
            $this->cambiar('profiles', $p->id, 'screens', $p->screens, true);
        }
        foreach (DB::table('users')->get(['id', 'screens_added', 'screens_removed']) as $u) {
            $this->cambiar('users', $u->id, 'screens_added', $u->screens_added, true);
            $this->cambiar('users', $u->id, 'screens_removed', $u->screens_removed, false);
        }
    }

    public function down(): void
    {
        // No se revierte: la pantalla Comercializadoras ya no existe en el código.
    }

    private function cambiar(string $tabla, int $id, string $columna, ?string $json, bool $darTerceros): void
    {
        $pantallas = json_decode($json ?? '[]', true) ?: [];
        if (!in_array('comercializadoras', $pantallas)) {
            return;
        }
        $pantallas = array_values(array_diff($pantallas, ['comercializadoras']));
        if ($darTerceros && !in_array('terceros', $pantallas)) {
            $pantallas[] = 'terceros';
        }
        DB::table($tabla)->where('id', $id)->update([$columna => json_encode($pantallas)]);
    }
};
