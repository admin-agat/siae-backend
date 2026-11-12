<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 11/11/2026: el Dashboard pasa a ser el Tablero de operaciones (datos reales, sin dinero). Además de
// GERENCIA lo ven los perfiles de tipo EXPORTACIONES y COMERCIAL (decisión de Peter). Solo datos de
// permisos: no cambia el esquema.
return new class extends Migration
{
    private const PANTALLA = 'dashboard';
    private const TIPOS = ['EXPORTACIONES', 'COMERCIAL'];

    public function up(): void
    {
        foreach (DB::table('profiles')->whereIn('base_role', self::TIPOS)->get(['id', 'screens']) as $p) {
            $pantallas = json_decode($p->screens ?? '[]', true) ?: [];
            if (!in_array(self::PANTALLA, $pantallas)) {
                DB::table('profiles')->where('id', $p->id)->update(['screens' => json_encode([self::PANTALLA, ...$pantallas])]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('profiles')->whereIn('base_role', self::TIPOS)->get(['id', 'screens']) as $p) {
            $pantallas = json_decode($p->screens ?? '[]', true) ?: [];
            if (in_array(self::PANTALLA, $pantallas)) {
                DB::table('profiles')->where('id', $p->id)->update(['screens' => json_encode(array_values(array_diff($pantallas, [self::PANTALLA])))]);
            }
        }
    }
};
