<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 09/11/2026 (revisión de seguridad, etapa 2): se elimina la tabla "bookings" del primer diseño.
// Los bookings reales son los del Cierre de embarque (shipment_bookings). La pantalla vieja no estaba
// en el menú ni en el catálogo de pantallas. Por seguridad, si la tabla tiene datos NO se borra nada.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bookings')) {
            return;
        }
        $filas = DB::table('bookings')->count();
        $enLineas = Schema::hasColumn('shipment_lines', 'booking_id') ? DB::table('shipment_lines')->whereNotNull('booking_id')->count() : 0;
        if ($filas || $enLineas) {
            throw new RuntimeException("La tabla bookings tiene $filas filas y $enLineas líneas de embarque la usan: no se borra. Revisar antes.");
        }

        if (Schema::hasColumn('shipment_lines', 'booking_id')) {
            Schema::table('shipment_lines', function (Blueprint $t) {
                $t->dropForeign(['booking_id']);
                $t->dropColumn('booking_id');
            });
        }
        Schema::drop('bookings');
    }

    public function down(): void
    {
        // Misma estructura que tenía la tabla al borrarla (vacía)
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_number');
            $table->foreignId('shipping_line_id')->nullable()->constrained('shipping_lines');
            $table->unsignedSmallInteger('week');
            $table->unsignedSmallInteger('year');
            $table->date('entry_date');
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->foreignId('vessel_id')->nullable()->constrained('vessels');
            $table->string('voyage_number')->nullable();
            $table->date('eta')->nullable();
            $table->unsignedSmallInteger('departure_week')->nullable();
            $table->unsignedSmallInteger('departure_year')->nullable();
            $table->foreignId('destination_id')->nullable()->constrained('destinations');
            $table->foreignId('port_id')->nullable()->constrained('ports');
            $table->integer('weekly_quota')->nullable();
            $table->date('estimated_departure')->nullable();
            $table->integer('boxes_quantity')->nullable();
            $table->foreignId('client_id')->nullable()->constrained('customers');
            $table->index(['week', 'year']);
        });
        Schema::table('shipment_lines', function (Blueprint $t) {
            $t->foreignId('booking_id')->nullable()->constrained('bookings');
            $t->index('booking_id');
        });
    }
};
