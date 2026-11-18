<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 17/11/2026: TARJAS CON HISTORIAL (fase 1a de docs/modulos/pagos-liquidaciones.md, sección 3.5).
//   1. Las tarjas ya no se borran: baja lógica con status y motivo, para que el historial (audit_logs)
//      quede completo desde que se crean hasta que se liquidan.
//   2. client_key: el celular manda una clave por camión nuevo; si la señal falla y reenvía, no se duplica.
// (El tipo base TARJADOR ya se creó en 2026_11_17_000002_tipo_tarjador.)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tally_sheets', function (Blueprint $table) {
            $table->boolean('status')->default(true);
            $table->string('deactivation_reason', 255)->nullable(); // motivo al quitar la tarja
            $table->uuid('client_key')->nullable()->unique();       // reenvío desde el celular sin duplicar
        });
        DB::statement('ALTER TABLE tally_sheets ADD CONSTRAINT tally_sheets_motivo_check CHECK (status OR deactivation_reason IS NOT NULL)');
        DB::statement('CREATE INDEX tally_sheets_compra_activa_idx ON tally_sheets (producer_quota_line_id) WHERE status');
    }

    public function down(): void
    {
        // Sin la columna status, las tarjas quitadas volverían a contar como activas: se detiene.
        if (DB::table('tally_sheets')->where('status', false)->exists()) {
            throw new RuntimeException('HAY TARJAS QUITADAS: NO SE PUEDE REVERTIR SIN QUE VUELVAN A SUMAR. REVÍSALAS ANTES.');
        }
        DB::statement('DROP INDEX IF EXISTS tally_sheets_compra_activa_idx');
        DB::statement('ALTER TABLE tally_sheets DROP CONSTRAINT IF EXISTS tally_sheets_motivo_check');
        Schema::table('tally_sheets', function (Blueprint $table) {
            $table->dropUnique(['client_key']);
            $table->dropColumn(['status', 'deactivation_reason', 'client_key']);
        });
    }
};
