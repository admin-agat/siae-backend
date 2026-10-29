<?php

// Total de cajas de la semana (28/10/2026). Exportaciones escribe primero el total que pide
// Rusia (ej. 150 000 cajas para la semana 50) y el pedido solo se guarda cuando la suma
// de las líneas cuadra exactamente con ese total.
// Nullable en la base solo por seguridad con registros viejos; el controlador lo exige.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_orders', function (Blueprint $table) {
            $table->unsignedInteger('expected_boxes')->nullable()->after('year');
        });
    }

    public function down(): void
    {
        Schema::table('customer_orders', function (Blueprint $table) {
            $table->dropColumn('expected_boxes');
        });
    }
};
