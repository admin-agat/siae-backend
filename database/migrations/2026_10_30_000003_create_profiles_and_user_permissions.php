<?php

// Permisos por persona (30/10/2026): perfiles editables (nombre + tipo base + pantallas) y, por
// usuario, su perfil, de quién es asistente y las pantallas que se le agregan o quitan.
// Los perfiles iniciales reproducen lo que veía cada rol: nadie gana ni pierde acceso al migrar.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERFILES = [
        'ADMIN'                  => ['ADMINISTRADOR', 'Todo el sistema, usuarios, perfiles y bitácora.'],
        'COORDINADOR_INVENTARIO' => ['COORDINADOR DE INVENTARIO', 'Inventario completo; confirma transferencias y corrige movimientos.'],
        'JEFE_BODEGA'            => ['JEFE DE BODEGA', 'Inventario (sin corregir movimientos ya registrados).'],
        'BODEGUERO'              => ['BODEGUERO', 'Solo su bodega: stock, movimientos y despacho de materiales.'],
        'EXPORTACIONES'          => ['EXPORTACIONES', 'Pedido semanal, distribución en barcos, cierre de embarque, navieras y bookings.'],
        'COMERCIAL'              => ['COMERCIAL', 'Compra de fruta y precios oficiales MAG.'],
    ];

    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            // Tipo base: reglas que dependen del rol (bodeguero = solo su bodega, coordinador corrige movimientos...)
            $table->string('base_role', 30);
            $table->string('description')->nullable();
            $table->jsonb('screens')->default('[]'); // claves de config/pantallas.php
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('profile_id')->nullable()->after('role')->constrained('profiles');
            $table->foreignId('boss_id')->nullable()->after('profile_id')->constrained('users'); // "asistente de"
            $table->jsonb('screens_added')->default('[]')->after('boss_id');   // excepciones: pantallas de más
            $table->jsonb('screens_removed')->default('[]')->after('screens_added'); // excepciones: pantallas de menos
        });

        $pantallas = require config_path('pantallas.php');
        $ahora = now();
        foreach (self::PERFILES as $rol => [$nombre, $descripcion]) {
            $deRol = $rol === 'ADMIN' ? [] : array_keys(array_filter($pantallas, fn ($p) => in_array($rol, $p['roles'], true)));
            $id = DB::table('profiles')->insertGetId([
                'name' => $nombre, 'base_role' => $rol, 'description' => $descripcion,
                'screens' => json_encode($deRol), 'status' => true, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
            DB::table('users')->where('role', $rol)->update(['profile_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('boss_id');
            $table->dropConstrainedForeignId('profile_id');
            $table->dropColumn(['screens_added', 'screens_removed']);
        });
        Schema::dropIfExists('profiles');
    }
};
