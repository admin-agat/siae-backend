<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 12/11/2026 (diseño aprobado por Peter): Departamento LEGAL. Lleva los contratos de productores (cupo, precio,
// fechas, código bananero, finca, N° MAGAP) y las cajas registradas en el MAG, en la pantalla nueva
// 'contratos_legal'. Liquidaciones ya no mete contratos: su pantalla 'contratos_productores' queda como
// "Retención de productores" (régimen y referencia de pago) y ve los contratos sin cambiarlos.
// Agrega el tipo base LEGAL (la base valida los tipos) y su perfil. No le quita pantallas a nadie.
return new class extends Migration
{
    private const ROLES_ANTES = "'ADMIN','JEFE_BODEGA','COORDINADOR_INVENTARIO','BODEGUERO','EXPORTACIONES','COMERCIAL','GERENCIA','FINANZAS','CONTABILIDAD','DEPARTAMENTO','LIQUIDACIONES'";

    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN (' . self::ROLES_ANTES . ",'LEGAL'))");
        // el control de tipos de perfil (2026_11_10_000002) también
        DB::statement('ALTER TABLE profiles DROP CONSTRAINT IF EXISTS profiles_base_role_check');
        DB::statement('ALTER TABLE profiles ADD CONSTRAINT profiles_base_role_check CHECK (base_role IN (' . self::ROLES_ANTES . ",'LEGAL'))");
        if (!DB::table('profiles')->where('name', 'LEGAL')->exists()) {
            DB::table('profiles')->insert([
                'name' => 'LEGAL', 'base_role' => 'LEGAL',
                'description' => 'Departamento Legal: contratos de productores (cupo, precio, fechas, código bananero, finca, N° MAGAP) y cajas registradas en el MAG.',
                'screens' => json_encode(['contratos_legal']),
                'status' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('profiles')->where('name', 'LIQUIDACIONES')->where('base_role', 'LIQUIDACIONES')->update([
            'description' => 'Departamento de Liquidaciones: retención de productores, parámetros de la semana, tarjas, liquidaciones y pagos. Ve los contratos (los registra LEGAL).',
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('profiles')->where('base_role', 'LEGAL')->whereNotExists(
            fn ($q) => $q->select(DB::raw(1))->from('users')->whereColumn('users.profile_id', 'profiles.id')
        )->delete();
        if (!DB::table('users')->where('role', 'LEGAL')->exists()) {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
            DB::statement('ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN (' . self::ROLES_ANTES . '))');
        }
        if (!DB::table('profiles')->where('base_role', 'LEGAL')->exists()) {
            DB::statement('ALTER TABLE profiles DROP CONSTRAINT IF EXISTS profiles_base_role_check');
            DB::statement('ALTER TABLE profiles ADD CONSTRAINT profiles_base_role_check CHECK (base_role IN (' . self::ROLES_ANTES . '))');
        }
        DB::table('profiles')->where('name', 'LIQUIDACIONES')->where('base_role', 'LIQUIDACIONES')->update([
            'description' => 'Departamento de Liquidaciones: contratos y retención de productores, parámetros de la semana, tarjas, liquidaciones y pagos.',
            'updated_at'  => now(),
        ]);
    }
};
