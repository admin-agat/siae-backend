<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cabecera del cupo asignado a un productor para una semana bananera
        Schema::create('producer_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('third_party_id')->constrained('third_parties'); // productor
            $table->unsignedSmallInteger('week_number'); // semana bananera ISO 8601
            $table->unsignedSmallInteger('year');
            $table->decimal('total_cupo', 12, 2); // suma de todas las variedades (ej. 1400)
            $table->string('status', 20)->default('PENDIENTE'); // PENDIENTE / DESPACHADO
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        // Detalle: cuánto cupo corresponde a cada variedad (GLOBAL, PALM, etc.)
        Schema::create('producer_quota_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producer_quota_id')->constrained('producer_quotas')->cascadeOnDelete();
            $table->string('variety', 30); // GLOBAL / PALM / COOL_EMERALD / etc.
            $table->decimal('quantity_cajas', 12, 2); // ej. 700
            $table->timestamps();
        });

        // Receta/BOM: cuánto de cada insumo corresponde por caja, según variedad o cupo total
        Schema::create('material_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supply_id')->constrained('supplies'); // insumo real (TAPA GLOBAL VILLAGE, ALUMBRE I, etc.)
            $table->string('base_cupo', 10); // 'GLOBAL' | 'PALM' | 'TOTAL' — de cuál cupo se toma la base del cálculo
            $table->decimal('ratio_per_box', 12, 6); // cantidad de este insumo por 1 caja del base_cupo
            $table->string('unit', 10); // LIB, MT, UNI, FCO, MTS — informativo, debe calzar con supplies.unit
            $table->boolean('status')->default(true); // patrón deactivate/reactivate del sistema
            $table->timestamps();

            // Un mismo insumo no debería tener dos fórmulas para el mismo cupo base
            $table->unique(['supply_id', 'base_cupo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_recipes');
        Schema::dropIfExists('producer_quota_lines');
        Schema::dropIfExists('producer_quotas');
    }
};