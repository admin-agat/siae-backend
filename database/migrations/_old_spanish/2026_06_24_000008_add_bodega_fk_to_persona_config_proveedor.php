<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Agrega la FK de bodega_id en persona_config_proveedor, que se dejó
     * suelta en la migración original (2026_06_22_000002) porque
     * terceros.bodegas todavía no existía. Ahora que sí existe, se
     * conecta correctamente.
     */
    public function up(): void
    {
        Schema::table('terceros.persona_config_proveedor', function (Blueprint $table) {
            $table->foreign('bodega_id')
                ->references('id')
                ->on('inventario.bodegas');
        });
    }

    public function down(): void
    {
        Schema::table('terceros.persona_config_proveedor', function (Blueprint $table) {
            $table->dropForeign(['bodega_id']);
        });
    }
};
