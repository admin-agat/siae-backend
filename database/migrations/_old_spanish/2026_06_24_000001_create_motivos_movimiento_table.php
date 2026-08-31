<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Catálogo de motivos para movimientos de bodega (ingreso o egreso).
     * En vez de tener una tabla/pantalla separada para cada caso de uso
     * (Despacho a productor, Ajuste, Merma, etc.), todos los movimientos
     * de inventario usan la misma tabla `movimientos_bodega`, diferenciados
     * por el motivo seleccionado aquí. Esto evita duplicar columnas
     * (bodega, fecha, semana, productos, totales) en múltiples tablas.
     *
     * tipo_permitido indica si el motivo aplica para ingreso, egreso, o
     * ambos (ej. "Ajuste de inventario" puede ser positivo o negativo).
     *
     * Ejemplos esperados de datos iniciales (se insertan vía seeder, no
     * en esta migración):
     *   INGRESO: Compra a proveedor, Devolución de cliente, Ajuste positivo
     *   EGRESO:  Despacho a productor, Consumo propio, Merma, Ajuste negativo,
     *            Transferencia a otra bodega
     */
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS inventario');

        Schema::create('inventario.motivos_movimiento', function (Blueprint $table) {
            $table->id();

            $table->string('nombre', 100)->unique();

            $table->string('tipo_permitido', 10)
                ->comment('ingreso | egreso | ambos');

            $table->boolean('requiere_persona')->default(false)
                ->comment('true si este motivo necesita vincular un proveedor/productor (ej. Despacho a productor)');

            $table->boolean('activo')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventario.motivos_movimiento');
    }
};
