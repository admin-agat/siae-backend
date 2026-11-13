<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 13/11/2026 (docs/modulos/compra-global-division.md, aprobado por Peter):
// Compra global de una comercializadora que se divide después. Una compra puede guardarse
// POR DIVIDIR (sin productor real ni finca) y al llegar el cuadro de despacho se divide en
// filas por productor y finca. Cada fila nueva recuerda de qué compra salió.
// Además corrige la FK de user_notifications: quitar una compra enviada con avisos daba error 500.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('producer_quota_lines', function (Blueprint $table) {
            // POR DIVIDIR: el vendedor (comercializadora) todavía no manda el detalle de productores
            $table->boolean('pending_split')->default(false);
            // Traza de la división: fila de origen, cuándo y quién
            $table->foreignId('split_from_line_id')->nullable()
                ->constrained('producer_quota_lines')->nullOnDelete();
            $table->timestamp('split_at')->nullable();
            $table->foreignId('split_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index('split_from_line_id');
        });

        // Una compra POR DIVIDIR no lleva productor ni finca (evita estados a medias)
        DB::statement('ALTER TABLE producer_quota_lines ADD CONSTRAINT producer_quota_lines_pending_split_check
            CHECK (NOT pending_split OR (producer_id IS NULL AND farm_id IS NULL))');
        // Una fila no puede salir de sí misma
        DB::statement('ALTER TABLE producer_quota_lines ADD CONSTRAINT producer_quota_lines_split_from_check
            CHECK (split_from_line_id IS NULL OR split_from_line_id <> id)');
        // Para el tablero (AL5b) y el aviso de liquidación: compras pendientes por cupo
        DB::statement('CREATE INDEX producer_quota_lines_pending_split_idx
            ON producer_quota_lines (producer_quota_id) WHERE pending_split');

        // Corrección: la FK de avisos no tenía ON DELETE; quitar una compra enviada con avisos fallaba.
        // El aviso queda (ya leído o no) sin la compra; la bitácora conserva el detalle.
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropForeign(['producer_quota_line_id']);
            $table->foreign('producer_quota_line_id')->references('id')->on('producer_quota_lines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropForeign(['producer_quota_line_id']);
            $table->foreign('producer_quota_line_id')->references('id')->on('producer_quota_lines');
        });

        DB::statement('DROP INDEX IF EXISTS producer_quota_lines_pending_split_idx');
        DB::statement('ALTER TABLE producer_quota_lines DROP CONSTRAINT IF EXISTS producer_quota_lines_split_from_check');
        DB::statement('ALTER TABLE producer_quota_lines DROP CONSTRAINT IF EXISTS producer_quota_lines_pending_split_check');

        Schema::table('producer_quota_lines', function (Blueprint $table) {
            $table->dropForeign(['split_from_line_id']);
            $table->dropForeign(['split_by']);
            $table->dropIndex(['split_from_line_id']);
            $table->dropColumn(['pending_split', 'split_from_line_id', 'split_at', 'split_by']);
        });
    }
};
