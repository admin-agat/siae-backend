<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Ej: "GLOBAL VILLAGE", "PALM CON BANCA"
            // Código estable en mayúsculas con guion bajo, usado como identificador
            // en el código (material_recipes, cálculo de despacho, etc.) para que
            // nunca dependamos del texto libre de 'name' ni de su id numérico.
            // Ej: 'GLOBAL_VILLAGE', 'PALM_BANANA', 'PALM_CON_BANCA', 'DONA_ELENA'
            $table->string('code')->unique();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};