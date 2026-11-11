<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 11/11/2026 (pedido de Comercial para cerrar Compra de fruta con contratos):
//   - Contratos como el Excel "REGISTROS DE CONTRATOS DE BANANO": código único bananero y finca de cada
//     contrato; cajas registradas en el MAG por vendedor (el Excel las da por productor, no por contrato).
//   - Transporte RECONOCIDO: cuando el productor pone el transporte y se le reconoce $/caja (ej. 0,30). Se
//     paga solo por las cajas que llegaron según tarja; es lo contrario del transporte que se le descuenta.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('banana_code', 60)->nullable();  // código único bananero (ej. C0157, 2042-2061)
            $table->string('farm_name', 120)->nullable();   // nombre de la finca del contrato (S/N si no tiene)
        });
        Schema::table('third_parties', function (Blueprint $table) {
            $table->unsignedInteger('magap_registered_boxes')->nullable(); // cajas semanales registradas en el MAG
        });
        Schema::table('producer_quota_lines', function (Blueprint $table) {
            $table->decimal('transport_recognized_rate', 8, 4)->nullable(); // $/caja que se le reconoce; null = no
        });
        Schema::table('settlement_lines', function (Blueprint $table) {
            $table->decimal('transport_recognized', 10, 2)->default(0); // pagadas × tarifa reconocida
        });
        Schema::table('settlements', function (Blueprint $table) {
            $table->decimal('transport_recognized_amount', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('settlements', fn (Blueprint $t) => $t->dropColumn('transport_recognized_amount'));
        Schema::table('settlement_lines', fn (Blueprint $t) => $t->dropColumn('transport_recognized'));
        Schema::table('producer_quota_lines', fn (Blueprint $t) => $t->dropColumn('transport_recognized_rate'));
        Schema::table('third_parties', fn (Blueprint $t) => $t->dropColumn('magap_registered_boxes'));
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn(['banana_code', 'farm_name']));
    }
};
