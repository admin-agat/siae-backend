<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movement_lines', function (Blueprint $table) {
            $table->id();

            // A qué movimiento (Ingreso/Egreso) pertenece esta línea.
            // cascadeOnDelete: si se borra la cabecera, se borran sus líneas.
            $table->foreignId('inventory_movement_id')
                ->constrained('inventory_movements')
                ->cascadeOnDelete();

            $table->foreignId('supply_id')->constrained('supplies');

            $table->decimal('quantity', 12, 2);           // cantidad movida
            $table->decimal('unit_cost', 10, 2)->default(0); // costo/tarifa por unidad
            $table->decimal('discount', 10, 2)->default(0);  // descuento en esta línea
            $table->decimal('total', 12, 2)->default(0);     // (quantity * unit_cost) - discount

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movement_lines');
    }
};