<?php

// FASE 2 del flujo de pedidos (28/10/2026): Logística distribuye el pedido semanal en barcos.
//   shipments      = un barco que sale esa semana (naviera, barco, puerto de salida, destino, fechas)
//   shipment_lines = cuántas cajas de UNA línea del pedido van en ESE barco
// Una línea del pedido puede repartirse en varios barcos (varias shipment_lines).
//
// Las tablas estaban vacías. Cambios:
//   shipments:      + customer_order_id, vessel_id, destination_port_id, eta
//   shipment_lines: + customer_order_line_id; sku_id y destination_port_id pasan a opcionales
//                   (el SKU aún no existe en el sistema; el destino va en el barco)

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('customer_order_id')->nullable()->constrained('customer_orders');
            $table->foreignId('vessel_id')->nullable()->constrained('vessels');
            // Puerto de llegada (ST PETERSBURG...). El de salida ya existía: departure_port_id
            $table->foreignId('destination_port_id')->nullable()->constrained('ports');
            $table->date('eta')->nullable();
            $table->index(['customer_order_id', 'status']);
        });

        Schema::table('shipment_lines', function (Blueprint $table) {
            $table->foreignId('customer_order_line_id')->nullable()->constrained('customer_order_lines');
            $table->unsignedBigInteger('sku_id')->nullable()->change();
            $table->unsignedBigInteger('destination_port_id')->nullable()->change();
            // Una línea del pedido aparece una sola vez por barco
            $table->unique(['shipment_id', 'customer_order_line_id']);
        });
    }

    public function down(): void
    {
        Schema::table('shipment_lines', function (Blueprint $table) {
            $table->dropUnique(['shipment_id', 'customer_order_line_id']);
            $table->dropConstrainedForeignId('customer_order_line_id');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['customer_order_id', 'status']);
            $table->dropConstrainedForeignId('customer_order_id');
            $table->dropConstrainedForeignId('vessel_id');
            $table->dropConstrainedForeignId('destination_port_id');
            $table->dropColumn('eta');
        });
    }
};
