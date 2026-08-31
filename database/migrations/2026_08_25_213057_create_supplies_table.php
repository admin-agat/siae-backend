<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplies', function (Blueprint $table) {
            $table->id();

            // A qué categoría pertenece (ej. "TAPA CARTÓN 001" pertenece a la categoría "TAPA")
            $table->foreignId('supply_category_id')
                ->constrained('supply_categories')
                ->cascadeOnDelete();

            $table->string('code')->unique();  // código interno del insumo
            $table->string('name');            // ej. "TAPA CARTÓN ESTÁNDAR"
            $table->string('unit');            // unidad de medida: CAJAS, LIBRAS, ROLLOS, SACOS, UNIDAD
            $table->decimal('cost', 10, 2)->default(0); // costo unitario de referencia
            $table->boolean('status')->default(true);   // soft state, no physical deletion
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplies');
    }
};