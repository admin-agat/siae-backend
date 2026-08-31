<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Detalle (líneas) de un movimiento de bodega. Confirmado con la tabla
     * real "Buscar producto" en las pantallas de Ingreso/Egreso a bodega:
     * Código, Descripción, Unidad, Cantidad, Tarifa, Descuento, Costo,
     * Precio U, Total.
     *
     * `producto_id` referencia el catálogo de productos de inventario
     * (insumos/materiales), que es distinto del catálogo de SKU (receta
     * de venta) ya existente en public.skus.
     */
    public function up(): void
    {
        Schema::create('inventario.movimiento_lineas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('movimiento_id')
                ->constrained('inventario.movimientos_bodega')
                ->cascadeOnDelete();

            $table->foreignId('producto_id')
                ->constrained('inventario.productos');

            $table->string('unidad', 20)->nullable();
            $table->decimal('cantidad', 12, 2);
            $table->decimal('tarifa', 6, 2)->default(0)
                ->comment('Porcentaje de IVA aplicado a esta línea, ej. 15.00');
            $table->decimal('descuento', 12, 2)->default(0);
            $table->decimal('costo', 12, 4)->default(0);
            $table->decimal('precio_u', 12, 4)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventario.movimiento_lineas');
    }
};
