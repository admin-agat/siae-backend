<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /*
        Convierte insumos.unidad_medida (enum texto) en insumos.unidad_medida_id
        (FK hacia inventario.unidades_medida).
    */
    public function up(): void
    {
        // 1. Agregar la nueva columna FK (nullable temporalmente para migrar datos)
        Schema::table('inventario.insumos', function (Blueprint $table) {
            $table->foreignId('unidad_medida_id')
                ->nullable()
                ->references('id')
                ->on('inventario.unidades_medida');
        });

        // 2. Migrar datos existentes: cruzar el texto del enum con el código de la nueva tabla
        DB::statement("
            UPDATE inventario.insumos i
            SET unidad_medida_id = u.id
            FROM inventario.unidades_medida u
            WHERE i.unidad_medida::text = u.codigo
        ");

        // 3. Eliminar la columna enum vieja
        Schema::table('inventario.insumos', function (Blueprint $table) {
            $table->dropColumn('unidad_medida');
        });

        // 4. Hacer la nueva columna obligatoria (ya migrados los datos existentes)
        Schema::table('inventario.insumos', function (Blueprint $table) {
            $table->foreignId('unidad_medida_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('inventario.insumos', function (Blueprint $table) {
            $table->dropForeign(['unidad_medida_id']);
            $table->dropColumn('unidad_medida_id');

            $table->enum('unidad_medida', [
                'ML', 'LITROS', 'GALONES', 'GRAMOS', 'LIBRAS', 'KILO',
                'METROS', 'CENTIMETROS', 'ROLLOS', 'SACOS', 'CAJAS', 'UNIDAD',
            ])->nullable();
        });
    }
};