<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 10/11/2026: Comercial "envía a bodega" cada compra cuando está lista para despachar materiales.
// Solo las compras enviadas salen en la bandeja "Por despachar" y en los cupos del Despacho.
// Si después de enviarla cambian las cajas, la marca o la bodega, se guarda cuándo (aviso "CAMBIÓ").
// Las compras que ya existían quedan como enviadas (la bodega las seguía viendo antes de este cambio).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('producer_quota_lines', function (Blueprint $t) {
            $t->timestamp('sent_to_warehouse_at')->nullable();
            $t->foreignId('sent_to_warehouse_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('changed_after_sent_at')->nullable();
            $t->index('sent_to_warehouse_by');
        });

        DB::table('producer_quota_lines')
            ->whereNotNull('customer_id')
            ->update(['sent_to_warehouse_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('producer_quota_lines', function (Blueprint $t) {
            $t->dropForeign(['sent_to_warehouse_by']);
            $t->dropIndex(['sent_to_warehouse_by']);
            $t->dropColumn(['sent_to_warehouse_at', 'sent_to_warehouse_by', 'changed_after_sent_at']);
        });
    }
};
