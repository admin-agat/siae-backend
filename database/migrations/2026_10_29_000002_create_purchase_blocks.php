<?php

// COMPRA POR BARCO + MARCA, como la hoja semanal de Comercial (29/10/2026)
//
// Hoja "wk 37": cada bloque es barco (con su viaje) + marca + modalidad + puerto + META de cajas,
// y debajo las compras: comercial o productor, productor real, finca, código MAG, ejecutivo,
// día de corte, cajas, precio, CONTRATO/SPOT y lo negociado de transporte del contenedor.
//
// - shipments.voyage: el viaje (UNI1401) va en el embarque; el barco (FRIO STAR) es fijo.
// - purchase_blocks: bloques de compra de la semana.
// - producer_quota_lines: cada compra cuelga de un bloque. third_party_id del cupo = a quién
//   se le compra y se le paga (comercial o productor); producer_id = productor real si se le
//   compró a un comercial. Se quita el UNIQUE (cupo, marca, cliente): un mismo vendedor
//   puede tener varias compras de la misma marca (distintas fincas, días o barcos).
// - tally_sheets.container_number: N° de contenedor de la tarja.
// - settlements / settlement_lines: descuento de transporte negociado.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('voyage', 20)->nullable(); // N° de viaje, ej. UNI1401
        });

        Schema::create('purchase_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_order_id')->constrained('customer_orders');
            $table->foreignId('shipment_id')->constrained('shipments');   // barco de la semana
            $table->foreignId('brand_id')->constrained('brands');
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('cargo_mode', 15)->default('BAJO_CUBIERTA');   // BAJO_CUBIERTA / CONTENEDOR
            $table->foreignId('departure_port_id')->nullable()->constrained('ports');
            $table->unsignedInteger('target_boxes')->default(0);          // meta del bloque
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->index('customer_order_id');
        });
        DB::statement("ALTER TABLE purchase_blocks ADD CONSTRAINT purchase_blocks_cargo_mode_check CHECK (cargo_mode IN ('BAJO_CUBIERTA','CONTENEDOR'))");

        Schema::table('producer_quota_lines', function (Blueprint $table) {
            $table->dropUnique('producer_quota_lines_cupo_marca_cliente_unique');
            $table->foreignId('purchase_block_id')->nullable()->constrained('purchase_blocks');
            $table->foreignId('producer_id')->nullable()->constrained('third_parties'); // productor real
            $table->string('farm_name', 120)->nullable();
            $table->string('mag_code', 20)->nullable();
            $table->string('executive', 60)->nullable();    // ejecutivo comercial (MARIA ELENA...)
            $table->date('cut_date')->nullable();            // día de corte
            $table->foreignId('delivery_port_id')->nullable()->constrained('ports'); // dónde entrega
            $table->decimal('transport_deduction', 10, 2)->default(0); // transporte negociado a descontar
            $table->string('notes', 255)->nullable();
            $table->index('purchase_block_id');
        });

        Schema::table('tally_sheets', function (Blueprint $table) {
            $table->string('container_number', 15)->nullable();
        });

        Schema::table('settlements', function (Blueprint $table) {
            $table->decimal('transport_amount', 12, 2)->default(0);
        });
        Schema::table('settlement_lines', function (Blueprint $table) {
            $table->decimal('transport_deduction', 10, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('settlement_lines', fn (Blueprint $t) => $t->dropColumn('transport_deduction'));
        Schema::table('settlements', fn (Blueprint $t) => $t->dropColumn('transport_amount'));
        Schema::table('tally_sheets', fn (Blueprint $t) => $t->dropColumn('container_number'));
        Schema::table('producer_quota_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_block_id');
            $table->dropConstrainedForeignId('producer_id');
            $table->dropConstrainedForeignId('delivery_port_id');
            $table->dropColumn(['farm_name', 'mag_code', 'executive', 'cut_date', 'transport_deduction', 'notes']);
            $table->unique(['producer_quota_id', 'variety', 'customer_id'], 'producer_quota_lines_cupo_marca_cliente_unique');
        });
        Schema::dropIfExists('purchase_blocks');
        Schema::table('shipments', fn (Blueprint $t) => $t->dropColumn('voyage'));
    }
};
