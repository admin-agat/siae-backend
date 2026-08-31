<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();

            // Cabecera: bodega, motivo, tercero (proveedor si es compra, o
            // productor si es "Despacho a productor")
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->foreignId('movement_reason_id')->constrained('movement_reasons');
            $table->foreignId('third_party_id')->nullable()->constrained('third_parties');

            // Denormalizado a propósito: aunque movement_reasons ya tiene su
            // propio 'type', guardarlo aquí también permite filtrar
            // Ingresos/Egresos sin tener que hacer JOIN cada vez.
            $table->enum('type', ['INGRESO', 'EGRESO']);

            $table->date('date');

            // Campos pendientes que se agregaron a la cabecera esta sesión
            $table->string('purchase_order')->nullable();   // orden de compra
            $table->unsignedTinyInteger('week')->nullable(); // semana bananera
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('delivery_note')->nullable();     // guía de remisión
            $table->text('reference')->nullable();            // comentario/observación

            // Quién lo registró (para auditoría, junto con warehouses.responsible_user_id)
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('status')->default(true); // soft state, no physical deletion
            $table->timestamps();

            $table->index(['warehouse_id', 'type', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};