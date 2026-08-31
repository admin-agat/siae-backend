<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Catálogo de bodegas físicas de AGAT-ECUAGREEN. Confirmado con
     * capturas reales (dropdown "Bodega: BODEGA PRINCIPAL - 001" en
     * Ingreso/Egreso a bodega) y con el resumen original del proyecto:
     * 1 bodega principal distribuye a ~10 bodegas regionales
     * (Machala, El Triunfo, San Juan, etc.).
     *
     * codigo es el número que aparece en el nombre compuesto que se ve
     * en pantalla ("BODEGA PRINCIPAL - 001"), capturado por separado
     * para poder ordenar/filtrar sin parsear texto.
     */
    public function up(): void
    {
        Schema::create('inventario.bodegas', function (Blueprint $table) {
            $table->id();

            $table->string('codigo', 10)->unique()
                ->comment('Ej: 001, 002... como aparece en "BODEGA PRINCIPAL - 001"');

            $table->string('nombre', 150);
            $table->string('zona', 100)->nullable()
                ->comment('Ej: Machala, El Triunfo, San Juan');

            $table->boolean('es_principal')->default(false);
            $table->boolean('activo')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario.bodegas');
    }
};