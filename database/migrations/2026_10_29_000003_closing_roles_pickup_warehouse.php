<?php

// EXPORTACIONES Y COMERCIAL EN PRODUCCIÓN (29/10/2026)
//
// 1) Roles EXPORTACIONES y COMERCIAL + users.status (desactivar un usuario sin borrarlo).
// 2) CIERRE DE EMBARQUE por booking, como la hoja de Logística "Asignación de carga WK-37":
//    shipment_bookings = una fila por booking del barco: cliente, marca, CONTRATO/SPOT, bodegas o
//    contenedores, booking, DAE, B/L, AUCP, orden, consignee/notify, cupo, EMBARCADO real,
//    INGRESO (tarja del puerto), pesos por caja, Forma A, estado de la DAE.
//    En la hoja real se embarca de más (+290 en bodega), un contenedor sale con 1199 cajas y lo
//    que falta de un CONTRATO se completa con SPOT en otro booking: aquí se registra lo REAL,
//    sin las reglas de contenedores completos de la distribución (que es el plan).
//    shipments.loading_from / loading_to = ventana de carga (09/09 – 13/09); departure_date es el zarpe.
// 3) producer_quota_lines.pickup_warehouse_id: bodega donde el vendedor retira cartón y
//    materiales para esa compra. El bodeguero solo despacha los cupos de su bodega.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('ADMIN','JEFE_BODEGA','COORDINADOR_INVENTARIO','BODEGUERO','EXPORTACIONES','COMERCIAL'))");
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('status')->default(true);
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->date('loading_from')->nullable();
            $table->date('loading_to')->nullable();
        });

        Schema::create('shipment_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('brand_id')->nullable()->constrained('brands');
            $table->string('negotiation_type', 10)->default('SPOT');       // CONTRATO / SPOT
            $table->string('cargo_mode', 15)->default('BODEGA');           // BODEGA (carga suelta) / CONTENEDOR
            $table->string('holds', 40)->nullable();                       // bodegas del barco: 2ABCDE
            $table->unsignedSmallInteger('containers_qty')->default(0);
            $table->string('booking_number', 30)->nullable();
            $table->string('dae_number', 30)->nullable();
            $table->string('bl_number', 30)->nullable();
            $table->string('aucp', 20)->nullable();
            $table->string('order_ref', 20)->nullable();                    // ORDEN (904P)
            $table->string('forma_a', 60)->nullable();
            $table->string('consignee_name', 150)->nullable();
            $table->string('consignee_address', 500)->nullable();
            $table->string('notify_name', 150)->nullable();
            $table->string('notify_address', 500)->nullable();
            $table->unsignedInteger('quota_boxes')->default(0);            // CUPO
            $table->unsignedInteger('shipped_boxes')->default(0);          // EMBARCADO
            $table->unsignedInteger('port_entry_boxes')->nullable();       // INGRESO (tarja del puerto)
            $table->unsignedInteger('sample_boxes')->default(0);
            $table->decimal('net_weight_per_box', 6, 2)->nullable();       // 19.40
            $table->decimal('gross_weight_per_box', 6, 2)->nullable();     // 20.80 / 20.90
            $table->string('dae_status', 40)->nullable();                  // REGULARIZADA, 21...
            $table->string('arrival_note', 40)->nullable();                // ESTADO: LLEGA SEM38
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index('shipment_id');
        });
        DB::statement("ALTER TABLE shipment_bookings ADD CONSTRAINT shipment_bookings_negotiation_check CHECK (negotiation_type IN ('CONTRATO','SPOT'))");
        DB::statement("ALTER TABLE shipment_bookings ADD CONSTRAINT shipment_bookings_cargo_mode_check CHECK (cargo_mode IN ('BODEGA','CONTENEDOR'))");

        Schema::table('producer_quota_lines', function (Blueprint $table) {
            $table->foreignId('pickup_warehouse_id')->nullable()->constrained('warehouses');
        });
    }

    public function down(): void
    {
        Schema::table('producer_quota_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('pickup_warehouse_id'));
        Schema::dropIfExists('shipment_bookings');
        Schema::table('shipments', fn (Blueprint $t) => $t->dropColumn(['loading_from', 'loading_to']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('status'));
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('ADMIN','JEFE_BODEGA','COORDINADOR_INVENTARIO','BODEGUERO'))");
    }
};
