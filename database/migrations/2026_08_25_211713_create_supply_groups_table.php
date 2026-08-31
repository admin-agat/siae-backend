<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // ej. "CARTÓN", "PLÁSTICOS", "MATERIAL CHICO", "QUÍMICOS", "CONTENEDORES", "BAJO CUBIERTA"

            // TRUE  = "Materiales x caja" -> se le da al productor para la cosecha,
            //         se descuenta de su saldo en la Liquidación (SaldoMaterialProductor).
            // FALSE = "Materiales de exportación" -> costo de AGAT para el contenedor,
            //         NO se le cobra al productor.
            $table->boolean('chargeable_to_producer')->default(true);

            $table->boolean('status')->default(true); // soft state, no physical deletion
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_groups');
    }
};