<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 17/11/2026 (pedido de Peter): tipo base TARJADOR para la persona que llena las tarjas en el puerto, desde
// celular o laptop. Solo puede tener la pantalla 'tarjas' (Profile::pantallasVedadas) y no ve precios ni montos:
// la orden de corte (productor, marca, finca, cajas) y los campos que llena. Agrega el tipo (la base valida los
// tipos) y su perfil. No le quita pantallas a nadie.
return new class extends Migration
{
    private const ROLES_ANTES = "'ADMIN','JEFE_BODEGA','COORDINADOR_INVENTARIO','BODEGUERO','EXPORTACIONES','COMERCIAL','GERENCIA','FINANZAS','CONTABILIDAD','DEPARTAMENTO','LIQUIDACIONES','LEGAL'";

    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN (' . self::ROLES_ANTES . ",'TARJADOR'))");
        DB::statement('ALTER TABLE profiles DROP CONSTRAINT IF EXISTS profiles_base_role_check');
        DB::statement('ALTER TABLE profiles ADD CONSTRAINT profiles_base_role_check CHECK (base_role IN (' . self::ROLES_ANTES . ",'TARJADOR'))");
        if (!DB::table('profiles')->where('name', 'TARJADOR')->exists()) {
            DB::table('profiles')->insert([
                'name' => 'TARJADOR', 'base_role' => 'TARJADOR',
                'description' => 'Personal de tarjas en el puerto: ve la orden de corte y llena las tarjas por camión. No ve precios ni montos.',
                'screens' => json_encode(['tarjas']),
                'status' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('profiles')->where('base_role', 'TARJADOR')->whereNotExists(
            fn ($q) => $q->select(DB::raw(1))->from('users')->whereColumn('users.profile_id', 'profiles.id')
        )->delete();
        if (!DB::table('users')->where('role', 'TARJADOR')->exists()) {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
            DB::statement('ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN (' . self::ROLES_ANTES . '))');
        }
        if (!DB::table('profiles')->where('base_role', 'TARJADOR')->exists()) {
            DB::statement('ALTER TABLE profiles DROP CONSTRAINT IF EXISTS profiles_base_role_check');
            DB::statement('ALTER TABLE profiles ADD CONSTRAINT profiles_base_role_check CHECK (base_role IN (' . self::ROLES_ANTES . '))');
        }
    }
};
