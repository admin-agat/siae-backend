<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movement_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name');                    // ej. "DESPACHO A PRODUCTOR", "MERMA"
            $table->enum('type', ['INGRESO', 'EGRESO']); // a qué lado del movimiento aplica este motivo
            $table->boolean('status')->default(true);   // soft state, no physical deletion
            $table->timestamps();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movement_reasons');
    }
};