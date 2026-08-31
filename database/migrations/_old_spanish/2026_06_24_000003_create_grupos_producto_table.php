<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Catálogo de Grupo de producto (nivel 2 de la jerarquía). Confirmado
     * con capturas reales: MATERIAL POST COSECHA, CARTÓN, MATERIAL DE
     * PALETIZADO Y CONTENEDOR, MATERIAL CIERRE CONTENEDOR.
     */
    public function up(): void
    {
        Schema::create('inventario.grupos_producto', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150)->unique();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario.grupos_producto');
    }
};
