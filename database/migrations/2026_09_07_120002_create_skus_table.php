<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skus', function (Blueprint $table) {
            $table->id();
            $table->string('sku_number')->unique(); // ej. "6001", "4002"
            $table->foreignId('brand_id')->constrained('brands');
            $table->foreignId('shipment_type_id')->constrained('shipment_types');
            $table->foreignId('box_weight_id')->constrained('box_weights');
            $table->foreignId('box_type_id')->constrained('box_types');
            $table->foreignId('package_type_id')->constrained('package_types');
            $table->foreignId('sticker_type_id')->constrained('sticker_types');
            $table->decimal('box_quantity', 12, 2)->comment('Cantidad de cajas por contenedor');
            $table->text('specification')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skus');
    }
};
