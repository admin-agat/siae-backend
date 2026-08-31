<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `contrato_precios` guarda el HISTORIAL de precios dentro de la vigencia
     * de un contrato. Un contrato puede cambiar de precio varias veces durante
     * su año de vigencia (ej. ajuste trimestral), por lo que el precio nunca
     * se sobreescribe: cada cambio crea un nuevo registro y se cierra el
     * anterior con `vigente_hasta`.
     *
     * `vigente_hasta = null` significa que ese es el precio actualmente activo.
     *
     * Esto permite que, al generar una Orden de Corte o un Pedido con
     * tipo_precio = 'contractual', el sistema busque el precio que estaba
     * vigente en la fecha exacta de esa operación — manteniendo trazabilidad
     * histórica completa para auditoría y liquidación.
     */
    public function up(): void
    {
        Schema::create('terceros.contrato_precios', function (Blueprint $table) {
            $table->id();

            $table->foreignId('contrato_id')
                ->constrained('terceros.contratos')
                ->cascadeOnDelete();

            $table->decimal('precio', 10, 4);

            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable()
                ->comment('NULL = este es el precio actualmente vigente');

            $table->text('observaciones')->nullable();

            $table->timestamps();

            $table->index(['contrato_id', 'vigente_desde']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terceros.contrato_precios');
    }
};
