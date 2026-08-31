<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Cabecera de un movimiento de bodega: Ingreso o Egreso. Confirmado
     * con capturas reales de Vástago Web ("Ingreso a bodega" / "Egreso a
     * bodega"). Ambas pantallas comparten la misma estructura, solo
     * cambia el campo `tipo` y el catálogo de motivos disponibles.
     *
     * `persona_id` es nullable porque no todos los motivos requieren un
     * tercero (ej. un Ajuste de inventario no tiene proveedor/productor,
     * pero un Despacho a productor o una Compra sí).
     *
     * `orden_compra_id` queda como referencia futura (FK pendiente,
     * aún no se ha diseñado el módulo de Orden de compras).
     */
    public function up(): void
    {
        Schema::create('inventario.movimientos_bodega', function (Blueprint $table) {
            $table->id();

            $table->string('tipo', 10)
                ->comment('ingreso | egreso');

            $table->unsignedInteger('numero')
                ->comment('Numeración correlativa por tipo (ingreso/egreso tienen su propia secuencia)');

            $table->date('fecha');

            $table->string('estado', 20)->default('ACTIVO');

            $table->foreignId('bodega_id')
                ->constrained('inventario.bodegas');

            $table->foreignId('motivo_id')
                ->constrained('inventario.motivos_movimiento');

            $table->foreignId('persona_id')
                ->nullable()
                ->comment('Proveedor (ingreso) o Productor (egreso/despacho), según el motivo')
                ->constrained('terceros.personas');

            $table->unsignedBigInteger('orden_compra_id')->nullable()
                ->comment('FK pendiente - módulo de Orden de compras aún no diseñado');

            $table->string('referencia', 100)->nullable();
            $table->string('guia_remision', 50)->nullable();
            $table->text('comentario')->nullable();

            $table->unsignedTinyInteger('semana');
            $table->unsignedSmallInteger('anio');

            // Totales (replican exactamente los campos vistos en la pantalla real)
            $table->decimal('subtotal_sin_impuestos', 12, 2)->default(0);
            $table->decimal('subtotal_iva', 12, 2)->default(0);
            $table->decimal('subtotal_0', 12, 2)->default(0);
            $table->decimal('subtotal_no_objeto_iva', 12, 2)->default(0);
            $table->decimal('subtotal_exento_iva', 12, 2)->default(0);
            $table->decimal('total_descuento', 12, 2)->default(0);
            $table->decimal('valor_ice', 12, 2)->default(0);
            $table->decimal('iva', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->timestamps();

            $table->unique(['tipo', 'numero']);
            $table->index(['bodega_id', 'semana', 'anio']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventario.movimientos_bodega');
    }
};
