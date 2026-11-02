<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 02/11/2026: el Dashboard es solo de gerencia. Tipo base nuevo GERENCIA con su perfil inicial;
// se quita el Dashboard de todos los demás perfiles y de las excepciones de cada persona.
return new class extends Migration
{
    private const PANTALLA = 'dashboard';
    private const ROLES_ANTES = "'ADMIN','JEFE_BODEGA','COORDINADOR_INVENTARIO','BODEGUERO','EXPORTACIONES','COMERCIAL'";

    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN (' . self::ROLES_ANTES . ",'GERENCIA'))");

        $this->quitar('profiles', 'screens', DB::table('profiles')->where('base_role', '!=', 'GERENCIA'));
        $this->quitar('users', 'screens_added', DB::table('users')->where('role', '!=', 'GERENCIA'));

        if (!DB::table('profiles')->where('name', 'GERENCIA')->exists()) {
            DB::table('profiles')->insert([
                'name' => 'GERENCIA', 'base_role' => 'GERENCIA', 'description' => 'Dashboard gerencial.',
                'screens' => json_encode([self::PANTALLA]), 'status' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('profiles')->where('base_role', 'GERENCIA')->whereNotExists(
            fn ($q) => $q->select(DB::raw(1))->from('users')->whereColumn('users.profile_id', 'profiles.id')
        )->delete();
        // Vuelve a dar el Dashboard a los perfiles de inventario, como estaba antes
        foreach (DB::table('profiles')->whereIn('base_role', ['COORDINADOR_INVENTARIO', 'JEFE_BODEGA'])->get(['id', 'screens']) as $p) {
            $pantallas = json_decode($p->screens ?? '[]', true) ?: [];
            if (!in_array(self::PANTALLA, $pantallas)) {
                DB::table('profiles')->where('id', $p->id)->update(['screens' => json_encode([self::PANTALLA, ...$pantallas])]);
            }
        }
        if (!DB::table('users')->where('role', 'GERENCIA')->exists()) {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
            DB::statement('ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN (' . self::ROLES_ANTES . '))');
        }
    }

    private function quitar(string $tabla, string $columna, $consulta): void
    {
        foreach ($consulta->get(['id', $columna]) as $fila) {
            $pantallas = json_decode($fila->$columna ?? '[]', true) ?: [];
            if (in_array(self::PANTALLA, $pantallas)) {
                DB::table($tabla)->where('id', $fila->id)->update([$columna => json_encode(array_values(array_diff($pantallas, [self::PANTALLA])))]);
            }
        }
    }
};
