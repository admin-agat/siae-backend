<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de insumos (inventario físico: químicos, cartón, plástico,
     * etiquetas, paletizado, etc.).
     * Cada insumo pertenece a una categoría (ej. "Tapa Doña Elena 18kg"
     * pertenece a la categoría "TAPA", que a su vez pertenece al grupo "CARTÓN").
     * Vive en el schema "inventario" de Postgres.
     */
    public function up(): void
    {
        Schema::create('inventario.insumos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_insumo_id')
                ->references('id')
                ->on('inventario.categorias_insumo');

            $table->string('codigo', 20)->unique();
            $table->string('nombre', 255);
            $table->text('descripcion')->nullable();

            $table->enum('unidad_medida', [
                'ML', 'LITROS', 'GALONES', 'GRAMOS', 'LIBRAS', 'KILO',
                'METROS', 'CENTIMETROS', 'ROLLOS', 'SACOS', 'CAJAS', 'UNIDAD',
            ]);

            $table->string('marca', 100)->nullable();
            $table->string('procedencia', 100)->default('ECUADOR');
            $table->decimal('costo', 10, 4)->default(0);

            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario.insumos');
    }
};