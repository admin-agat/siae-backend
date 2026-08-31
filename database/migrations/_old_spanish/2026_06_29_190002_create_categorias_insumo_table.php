<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /*
        Tabla de categorías de insumo (catálogo nivel 2).
        Cada categoría pertenece a un grupo (Ejemplo: Tapa pertenece a Cartón).
        Vive en el schema "inventario".
    */
    public function up(): void
    {
        Schema::create('inventario.categorias_insumo', function (Blueprint $table) {
            $table->id();

            $table->foreignId('grupo_insumo_id')
                ->references('id')
                ->on('inventario.grupos_insumo');

            $table->string('nombre', 100); // ej. "TAPA", "FONDO"
            $table->boolean('activo')->default(true);
            $table->timestamps();

            // una categoría no se repite dentro del mismo grupo
            $table->unique(['grupo_insumo_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario.categorias_insumo');
    }
};
