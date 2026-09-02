<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique(); // Ej: OC-2026-045
            $table->foreignId('third_party_id')->constrained('third_parties'); // Proveedor
            $table->foreignId('warehouse_id')->constrained('warehouses'); // Bodega destino
            $table->date('date');
            // PENDIENTE: aún no ha llegado nada. RECIBIDA_PARCIAL: llegó parte. RECIBIDA_COMPLETA: llegó todo. CANCELADA.
            $table->string('status', 30)->default('PENDIENTE');
            $table->text('reference')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};