<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /*
        Tabla catálogo de unidades de medida (ej. ML, LITROS, LIBRAS, KILO...).
        Antes era un enum fijo en insumos.unidad_medida; se convierte en
        catálogo para poder agregar unidades nuevas sin migrar.
        Vive en el schema "inventario".
    */
    public function up(): void
    {
        Schema::create('inventario.unidades_medida', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique(); // ej. "ML", "LITROS"
            $table->string('nombre', 50);            // ej. "Mililitros", "Litros"
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario.unidades_medida');
    }
};