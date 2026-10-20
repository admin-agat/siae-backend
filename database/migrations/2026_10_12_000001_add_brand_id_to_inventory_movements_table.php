<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Agrega la MARCA del retiro al despacho (un retiro = una sola marca).
// Sin esta columna, "ya retirado" mezcla los insumos compartidos entre marcas
// (químicos) y el recomendado de la segunda marca de la semana sale mal.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            // Nullable: los egresos manuales y los despachos viejos no tienen marca
            $table->foreignId('brand_id')
                ->nullable()
                ->constrained('brands');

            // PostgreSQL NO crea índice automático en las FK
            $table->index('brand_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            // Borra el índice, la FK y la columna
            $table->dropIndex(['brand_id']);
            $table->dropConstrainedForeignId('brand_id');
        });
    }
};