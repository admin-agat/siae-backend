<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de grupos de insumo (catálogo nivel 1).
     * Ej: Cartón, Plástico, Químicos, Etiquetas, Paletizado/Contenedor, Otros.
     * Vive en el schema "inventario" de Postgres.
     */
    public function up(): void
    {
        Schema::create('inventario.grupos_insumo', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique(); // ej. "CARTÓN", "PLÁSTICO"
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario.grupos_insumo');
    }
};