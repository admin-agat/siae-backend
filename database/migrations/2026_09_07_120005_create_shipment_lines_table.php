<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers');
            $table->unsignedSmallInteger('week');
            $table->foreignId('destination_port_id')->constrained('ports');
            $table->date('arrival_date')->nullable(); // Fecha llegada puerto
            $table->foreignId('sku_id')->constrained('skus');
            $table->foreignId('booking_id')->nullable()->constrained('bookings');
            $table->enum('type', ['CAJA', 'CONTENEDOR']);
            $table->decimal('container_quantity', 12, 2)->default(0);
            $table->decimal('box_quantity', 12, 2)->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->index('shipment_id');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_lines');
    }
};
