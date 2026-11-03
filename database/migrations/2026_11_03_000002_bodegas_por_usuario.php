<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 03/11/2026: bodegas asignadas a cada usuario (ej. el asistente de compras solo despacha y registra
// movimientos de las suyas). Sin filas = todas las bodegas. El BODEGUERO sigue con su bodega
// (warehouses.responsible_user_id), como antes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_warehouses');
    }
};
