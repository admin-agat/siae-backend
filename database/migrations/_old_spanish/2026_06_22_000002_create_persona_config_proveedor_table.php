<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `persona_config_proveedor` guarda los datos que solo aplican cuando
     * una persona tiene el rol es_proveedor o es_comercializadora activo
     * (relación 1 a 1 con personas).
     *
     * Incluye:
     * - Datos contables (cuentas, retenciones) para integración financiera.
     * - `habilitado_spot`: indica si ESTA persona puede operar bajo modalidad
     *   spot. Importante: esto NO es la misma decisión que el tipo_precio
     *   (contractual/spot) de cada línea de pedido o cupo de orden de corte.
     *   Un proveedor habilitado para spot puede aun así tener cupos
     *   contractuales puntuales; la decisión real siempre vive a nivel de
     *   línea (orden_corte_cupos), nunca aquí.
     */
    public function up(): void
    {
        Schema::create('terceros.persona_config_proveedor', function (Blueprint $table) {
            $table->id();

            $table->foreignId('persona_id')
                ->constrained('terceros.personas')
                ->cascadeOnDelete();

            // --- Bodega y clasificación ---
            $table->foreignId('bodega_id')
                ->nullable()
                ->comment('Bodega principal asociada a este proveedor');
            $table->string('tipo_proveedor', 100)->nullable()
                ->comment('Ej: PRODUCTOR, COMERCIALIZADORA, PROVEEDOR DE SERVICIOS');

            // --- Modalidad spot (habilitación, no decisión de negocio) ---
            $table->boolean('habilitado_spot')->default(false);

            // --- Datos específicos de productor agrícola ---
            $table->string('contrato_magap', 50)->nullable();
            $table->decimal('cant_cajas_contratado', 12, 2)->nullable();

            // --- Certificación ---
            $table->boolean('certificacion_bpa')->default(false)
                ->comment('Certificación de Buenas Prácticas Agrícolas');
            $table->decimal('certificacion_bpa_pct', 5, 2)->nullable();

            // --- Retenciones (referencia a catálogo de % de retención) ---
            $table->string('retencion_iva_bienes', 20)->nullable();
            $table->string('retencion_iva_servicios', 20)->nullable();
            $table->string('retencion_impto_renta', 20)->nullable();

            // --- Partidas contables ---
            $table->string('cta_x_pagar', 30)->nullable();
            $table->string('cta_costo_gasto', 30)->nullable();
            $table->string('cta_x_cobrar', 30)->nullable();
            $table->string('cta_anticipo', 30)->nullable();

            $table->timestamps();

            $table->unique('persona_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terceros.persona_config_proveedor');
    }
};
