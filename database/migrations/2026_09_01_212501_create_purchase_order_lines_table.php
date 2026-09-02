<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('supply_id')->constrained('supplies');
            $table->decimal('quantity_ordered', 12, 2); // Cantidad pedida en la OC (ej. 100 cajas)
            $table->decimal('unit_price', 12, 4); // Precio pactado con el proveedor
            $table->decimal('quantity_received', 12, 2)->default(0); // Cantidad que realmente llegó (se llena al recibir el Ingreso)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
    }
};