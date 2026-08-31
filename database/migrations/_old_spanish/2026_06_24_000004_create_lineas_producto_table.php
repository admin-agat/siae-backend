<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Catálogo de Línea de producto. CORREGIDO: Línea es un catálogo
     * PLANO e INDEPENDIENTE, sin dependencia jerárquica de Grupo.
     *
     * La coincidencia inicial (Químicos siempre con Material Post Cosecha)
     * era casualidad de los pocos ejemplos vistos, no una regla de negocio.
     * Confirmado con un ejemplo real de gestión de inventario genérico
     * donde Grupo es un único dropdown plano sin niveles anidados
     * (Consumibles, Empaques, Equipos, Herramientas, Materia Prima,
     * Producto Terminado, Químicos, Repuestos, Suministros).
     *
     * Línea puede usarse para variantes dentro de un mismo Grupo+Marca,
     * ej. Aceite La Favorita con Línea=Girasol o Línea=Palma, sin que
     * eso implique que Línea "pertenezca" a un Grupo fijo.
     */
    public function up(): void
    {
        Schema::create('inventario.lineas_producto', function (Blueprint $table) {
            $table->id();

            $table->string('nombre', 150)->unique();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario.lineas_producto');
    }
};
