<?php

// LIQUIDACIÓN FASE 0 — 17/11/2026 (diseño aprobado por Peter: docs/modulos/pagos-liquidaciones.md §3.3 y §10)
//   1. Tarjas: FALTANTES. Regla de Peter (17/11, con el personal de tarjas):
//        RECIBIDAS = EMBARCADAS − CAÍDAS − RECHAZADAS − FALTANTES. EMBARCADAS es el total (bruto).
//      RECIBIDAS no se guarda en la tarja (se calcula); en la liquidación queda congelada en boxes_paid.
//   2. Liquidaciones separadas por CONTRATO y SPOT (como el Excel): un cupo puede tener varias LQ activas,
//      una por contrato y una de SPOT. El índice único pasa de "una por cupo" a "una por cupo y grupo".
//   3. Retención IR banano congelada en la liquidación (régimen, base, tasa y monto).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Con la regla vieja (29/10) caídas y rechazadas eran aparte de las embarcadas: si alguna tarja
        // no cabe en la regla nueva, se detiene para revisarla con Liquidaciones antes de migrar.
        $malas = DB::table('tally_sheets')->whereRaw('boxes_fallen + boxes_rejected > boxes_shipped')->count();
        if ($malas > 0) {
            throw new RuntimeException("HAY {$malas} TARJAS CON CAÍDAS + RECHAZADAS MAYORES QUE LAS EMBARCADAS: REVÍSALAS ANTES DE MIGRAR.");
        }

        Schema::table('tally_sheets', function (Blueprint $table) {
            $table->unsignedInteger('boxes_missing')->default(0)->after('boxes_rejected'); // FALTANTES
        });
        DB::statement('ALTER TABLE tally_sheets ADD CONSTRAINT tally_sheets_boxes_nonneg_check CHECK (boxes_shipped >= 0 AND boxes_fallen >= 0 AND boxes_rejected >= 0 AND boxes_missing >= 0)');
        DB::statement('ALTER TABLE tally_sheets ADD CONSTRAINT tally_sheets_boxes_parts_check CHECK (boxes_fallen + boxes_rejected + boxes_missing <= boxes_shipped)');

        Schema::table('settlement_lines', function (Blueprint $table) {
            $table->unsignedInteger('boxes_missing')->default(0)->after('boxes_rejected');
        });

        Schema::table('settlements', function (Blueprint $table) {
            $table->unsignedInteger('boxes_missing')->default(0)->after('boxes_rejected');
            // Grupo de la LQ: CONTRATO (uno por contrato) o SPOT. Null en las liquidaciones viejas (mezcladas).
            $table->string('negotiation_type', 8)->nullable()->after('producer_quota_id');
            $table->foreignId('contract_id')->nullable()->after('negotiation_type')->constrained('contracts');
            $table->string('group_label', 40)->nullable()->after('contract_id'); // "CONTRATO 1", "SPOT"
            // Retención IR banano (RetencionBanano), congelada al liquidar
            $table->string('retention_regime', 12)->nullable()->after('credit_note_amount');
            $table->decimal('retention_base', 14, 2)->default(0)->after('retention_regime');
            $table->decimal('retention_rate', 7, 4)->default(0)->after('retention_base'); // 0,0120 = 1,20 %
            $table->decimal('retention_amount', 12, 2)->default(0)->after('retention_rate');
        });
        foreach (['settlement_lines', 'settlements'] as $t) {
            DB::statement("ALTER TABLE {$t} ADD CONSTRAINT {$t}_boxes_parts_check CHECK (boxes_fallen + boxes_rejected + boxes_missing <= boxes_shipped)");
        }
        DB::statement("ALTER TABLE settlements ADD CONSTRAINT settlements_negotiation_type_check CHECK (negotiation_type IS NULL OR negotiation_type IN ('CONTRATO', 'SPOT'))");
        DB::statement("ALTER TABLE settlements ADD CONSTRAINT settlements_contrato_check CHECK (negotiation_type IS DISTINCT FROM 'SPOT' OR contract_id IS NULL)");
        DB::statement('ALTER TABLE settlements ADD CONSTRAINT settlements_retention_check CHECK (retention_amount >= 0 AND retention_rate >= 0 AND retention_base >= 0)');

        DB::statement('DROP INDEX IF EXISTS settlements_cupo_activo_unique');
        DB::statement('CREATE UNIQUE INDEX settlements_grupo_activo_unique ON settlements (producer_quota_id, COALESCE(negotiation_type, \'\'), COALESCE(contract_id, 0)) WHERE status');
    }

    public function down(): void
    {
        // Ojo: si un cupo ya tiene varias LQ activas (contrato + spot), el índice viejo no se puede volver a crear
        DB::statement('DROP INDEX IF EXISTS settlements_grupo_activo_unique');
        DB::statement('CREATE UNIQUE INDEX settlements_cupo_activo_unique ON settlements (producer_quota_id) WHERE status');

        DB::statement('ALTER TABLE settlements DROP CONSTRAINT IF EXISTS settlements_retention_check');
        DB::statement('ALTER TABLE settlements DROP CONSTRAINT IF EXISTS settlements_contrato_check');
        DB::statement('ALTER TABLE settlements DROP CONSTRAINT IF EXISTS settlements_negotiation_type_check');
        foreach (['settlement_lines', 'settlements'] as $t) {
            DB::statement("ALTER TABLE {$t} DROP CONSTRAINT IF EXISTS {$t}_boxes_parts_check");
        }
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_id');
            $table->dropColumn(['boxes_missing', 'negotiation_type', 'group_label', 'retention_regime',
                'retention_base', 'retention_rate', 'retention_amount']);
        });
        Schema::table('settlement_lines', fn (Blueprint $table) => $table->dropColumn('boxes_missing'));

        DB::statement('ALTER TABLE tally_sheets DROP CONSTRAINT IF EXISTS tally_sheets_boxes_parts_check');
        DB::statement('ALTER TABLE tally_sheets DROP CONSTRAINT IF EXISTS tally_sheets_boxes_nonneg_check');
        Schema::table('tally_sheets', fn (Blueprint $table) => $table->dropColumn('boxes_missing'));
    }
};
