<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Catálogo de Tipo de producto (nivel 1 de la jerarquía de categorías).
     * Confirmado con capturas reales: PRODUCTOS TERMINADOS, SERVICIOS.
     * Servicios no maneja Unidad de bodega física (ej. flete, mano de obra).
     */
    public function up(): void
    {
        Schema::create('inventario.tipos_producto', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->boolean('maneja_inventario')->default(true)
                ->comment('false para Servicios, que no tiene existencia física en bodega');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario.tipos_producto');
    }
};
