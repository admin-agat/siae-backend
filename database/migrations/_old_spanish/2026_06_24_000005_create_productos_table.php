<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Catálogo de Productos de inventario/bodega (insumos, materiales,
     * empaques). Confirmado con 7 capturas reales de la pantalla
     * "Productos CA" en Vástago Web.
     *
     * Simplificado respecto al original: se mantiene 1 solo precio
     * (no los 5 campos Precio/Precio2/3/4/5Fra del sistema viejo, que
     * el usuario confirmó no se usan en la práctica).
     *
     * El precio con impuesto (segunda columna junto a "Precio" en la
     * captura real) NO se guarda como dato — se calcula en el momento
     * (precio * (1 + tarifa_iva/100)) porque depende de la tarifa vigente,
     * no es un valor fijo que deba persistirse.
     *
     * unidad: catálogo simple en texto, valores confirmados: Cajas,
     * Libras, Rollos, Sacos, Unidad.
     */
    public function up(): void
    {
        Schema::create('inventario.productos', function (Blueprint $table) {
            $table->id();

            $table->string('codigo_barra', 50)->nullable()->unique();
            $table->string('nombre', 200);
            $table->text('descripcion')->nullable();

            $table->foreignId('tipo_id')
                ->constrained('inventario.tipos_producto');

            $table->foreignId('grupo_id')
                ->nullable()
                ->constrained('inventario.grupos_producto');

            $table->foreignId('linea_id')
                ->nullable()
                ->constrained('inventario.lineas_producto');

            $table->string('unidad', 30)
                ->comment('Cajas, Libras, Rollos, Sacos, Unidad');

            $table->unsignedBigInteger('marca_id')->nullable()
                ->comment('FK a public.marcas, catálogo ya existente');

            $table->string('procede', 100)->nullable()
                ->comment('País de origen, ej. ECUADOR');

            $table->string('ice', 50)->nullable()
                ->comment('Catálogo de tasas ICE, capturado como texto por ahora');

            $table->decimal('tarifa_iva', 5, 2)->default(0)
                ->comment('Porcentaje de IVA: 15.00, 0.00, etc.');

            $table->string('medida_peso', 20)->nullable();
            $table->decimal('peso', 10, 4)->default(0);
            $table->decimal('descuento', 10, 2)->default(0);
            $table->decimal('costo', 12, 6)->default(0);
            $table->decimal('precio', 12, 6)->default(0)
                ->comment('Único precio (simplificado). El precio con IVA se calcula al vuelo.');

            $table->string('estado', 20)->default('ACTIVO');
            $table->boolean('activo')->default(true);

            $table->timestamps();

            $table->index(['tipo_id', 'grupo_id', 'linea_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario.productos');
    }
};
