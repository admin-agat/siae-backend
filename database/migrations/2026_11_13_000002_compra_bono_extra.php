<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 13/11/2026 (docs/modulos/compra-columnas-bono.md, aprobado por Peter):
//   BONO / EXTRA al productor: $/caja que se le paga APARTE del precio real (CINTA en fruta PACKED,
//   EMPAQUE ESPECIAL, FRUTA ADICIONAL u OTRO con su detalle). Se paga solo por las cajas que llegaron
//   según tarja (embarcadas + caídas), completo en el 1er pago como el transporte reconocido. No toca la
//   nota de crédito ni la base de retención. Al liquidar se congelan la tarifa, el motivo y el monto.
//   Las compras y liquidaciones existentes quedan sin bono (NULL / 0): no cambia ningún dato.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('producer_quota_lines', function (Blueprint $table) {
            $table->decimal('bonus_rate', 12, 4)->nullable();    // $/caja extra; NULL = sin bono
            $table->string('bonus_reason', 20)->nullable();      // CINTA / EMPAQUE_ESPECIAL / FRUTA_ADICIONAL / OTRO
            $table->string('bonus_detail', 100)->nullable();     // detalle en MAYÚSCULAS (obligatorio con OTRO)
        });

        // Catálogo cerrado y coherencia: la tarifa y el motivo van juntos; OTRO exige el detalle
        DB::statement("ALTER TABLE producer_quota_lines ADD CONSTRAINT producer_quota_lines_bonus_reason_check
            CHECK (bonus_reason IS NULL OR bonus_reason IN ('CINTA','EMPAQUE_ESPECIAL','FRUTA_ADICIONAL','OTRO'))");
        DB::statement('ALTER TABLE producer_quota_lines ADD CONSTRAINT producer_quota_lines_bonus_pair_check
            CHECK ((bonus_rate IS NULL) = (bonus_reason IS NULL))');
        DB::statement('ALTER TABLE producer_quota_lines ADD CONSTRAINT producer_quota_lines_bonus_rate_check
            CHECK (bonus_rate IS NULL OR (bonus_rate > 0 AND bonus_rate <= 5))');
        DB::statement("ALTER TABLE producer_quota_lines ADD CONSTRAINT producer_quota_lines_bonus_detail_check
            CHECK (bonus_reason IS DISTINCT FROM 'OTRO' OR bonus_detail IS NOT NULL)");

        Schema::table('settlement_lines', function (Blueprint $table) {
            $table->decimal('bonus_rate', 12, 4)->nullable();     // tarifa usada al liquidar (congelada)
            $table->string('bonus_reason', 20)->nullable();       // motivo congelado
            $table->decimal('bonus_amount', 12, 2)->default(0);   // pagadas × tarifa
        });

        Schema::table('settlements', function (Blueprint $table) {
            $table->decimal('bonus_amount', 12, 2)->default(0);   // suma del bono de sus líneas
        });
    }

    public function down(): void
    {
        Schema::table('settlements', fn (Blueprint $t) => $t->dropColumn('bonus_amount'));
        Schema::table('settlement_lines', fn (Blueprint $t) => $t->dropColumn(['bonus_rate', 'bonus_reason', 'bonus_amount']));

        DB::statement('ALTER TABLE producer_quota_lines DROP CONSTRAINT IF EXISTS producer_quota_lines_bonus_detail_check');
        DB::statement('ALTER TABLE producer_quota_lines DROP CONSTRAINT IF EXISTS producer_quota_lines_bonus_rate_check');
        DB::statement('ALTER TABLE producer_quota_lines DROP CONSTRAINT IF EXISTS producer_quota_lines_bonus_pair_check');
        DB::statement('ALTER TABLE producer_quota_lines DROP CONSTRAINT IF EXISTS producer_quota_lines_bonus_reason_check');
        Schema::table('producer_quota_lines', fn (Blueprint $t) => $t->dropColumn(['bonus_rate', 'bonus_reason', 'bonus_detail']));
    }
};
