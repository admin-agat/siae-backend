<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name');                 // ej. "BODEGA PRINCIPAL"
            $table->string('code')->unique();        // ej. "001" - código corto para mostrar en dropdowns

            // Responsable de la bodega: empleado de AGAT que hace login en SIAE
            // (registra envíos/recepción de material). Para auditoría: siempre
            // sabemos QUIEN movió qué en cada bodega.
            $table->foreignId('responsible_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('zone')->nullable();       // ubicación / región de la bodega
            $table->boolean('status')->default(true); // soft state, no physical deletion (mismo patrón que third_parties)
            $table->timestamps();

            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};