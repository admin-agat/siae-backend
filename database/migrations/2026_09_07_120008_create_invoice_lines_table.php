<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('shipment_line_id')->nullable()->constrained('shipment_lines');
            $table->foreignId('sku_id')->constrained('skus');
            $table->enum('unit_type', ['CAJA', 'CONTENEDOR']);
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 12, 4); // Manual, lo escribe Manuel
            $table->decimal('subtotal', 14, 2); // quantity * unit_price, calculado en backend
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
