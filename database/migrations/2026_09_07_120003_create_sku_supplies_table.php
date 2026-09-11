<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sku_supplies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sku_id')->constrained('skus')->cascadeOnDelete();
            $table->foreignId('supply_id')->constrained('supplies');
            $table->decimal('quantity', 12, 2)->comment('Cantidad consumida cada N cajas');
            $table->decimal('boxes_per_dose', 12, 2)->comment('N de cajas que corresponden a quantity (Cajas en el formulario viejo)');
            $table->boolean('is_dose')->default(true)->comment('Dosis');
            $table->boolean('per_container')->default(false)->comment('Por container');
            $table->boolean('chargeable_to_producer')->default(true)->comment('Cobro');
            $table->boolean('payable_to_supplier')->default(true)->comment('Pago');
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->unique(['sku_id', 'supply_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_supplies');
    }
};
