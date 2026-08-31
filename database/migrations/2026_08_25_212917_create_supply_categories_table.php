<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_categories', function (Blueprint $table) {
            $table->id();

            // A qué grupo pertenece (ej. "Tapa" pertenece al grupo "CARTÓN")
            $table->foreignId('supply_group_id')
                ->constrained('supply_groups')
                ->cascadeOnDelete();

            $table->string('name'); // ej. "TAPA", "FONDOS", "FUNDA 1", "QUATRO"
            $table->boolean('status')->default(true); // soft state, no physical deletion
            $table->timestamps();

            // Una categoría no se puede repetir dos veces dentro del mismo grupo
            $table->unique(['supply_group_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_categories');
    }
};