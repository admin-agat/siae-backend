<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 02/11/2026: la hoja de asignación de carga de Logística lleva por booking si la PROFORMA y el
// TURNO (del puerto) ya están pagados.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_bookings', function (Blueprint $table) {
            $table->boolean('proforma_paid')->default(false)->after('forma_a');
            $table->boolean('turn_paid')->default(false)->after('proforma_paid');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_bookings', function (Blueprint $table) {
            $table->dropColumn(['proforma_paid', 'turn_paid']);
        });
    }
};
