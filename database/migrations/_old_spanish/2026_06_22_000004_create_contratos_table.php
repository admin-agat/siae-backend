<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `contratos` representa la VIGENCIA de un contrato anual con una persona
     * (puede ser un Comprador del lado cliente, o un Productor/Comercializadora
     * del lado abastecimiento — el mismo modelo sirve para ambos lados).
     *
     * Reglas de negocio confirmadas:
     * - Un contrato cubre un solo SKU (no varios).
     * - El precio del contrato puede cambiar varias veces durante su vigencia
     *   (por eso el precio NO vive aquí, sino en la tabla contrato_precios,
     *   que guarda el historial completo).
     * - Esta tabla solo gestiona el "marco" del contrato: con quién, qué SKU,
     *   desde cuándo hasta cuándo, y su estado.
     */
    public function up(): void
    {
        Schema::create('terceros.contratos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('persona_id')
                ->comment('Comprador, Productor o Comercializadora con quien se firma el contrato')
                ->constrained('terceros.personas')
                ->cascadeOnDelete();

            $table->foreignId('sku_id')
                ->comment('Un contrato cubre exactamente un SKU');

            $table->string('numero_contrato', 50)->nullable()
                ->comment('Referencia/código del documento físico, si existe');

            $table->date('fecha_inicio');
            $table->date('fecha_fin');

            $table->decimal('cupo_total', 12, 2)->nullable()
                ->comment('Volumen total comprometido en el contrato, si aplica');

            $table->string('estado', 20)
                ->default('VIGENTE')
                ->comment('VIGENTE, VENCIDO, RENOVADO, ANULADO');

            $table->text('observaciones')->nullable();

            $table->timestamps();

            $table->index('estado');
            $table->index(['persona_id', 'sku_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terceros.contratos');
    }
};
