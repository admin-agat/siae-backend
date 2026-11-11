<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 11/11/2026 (revisión de rendimiento): liquidación, contratos (cajas por semana) y semana de compra buscan
// los cupos por año + semana de todos los vendedores; el índice único existente empieza por third_party_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('producer_quotas', function (Blueprint $table) {
            $table->index(['year', 'week_number'], 'producer_quotas_year_week_index');
        });
    }

    public function down(): void
    {
        Schema::table('producer_quotas', function (Blueprint $table) {
            $table->dropIndex('producer_quotas_year_week_index');
        });
    }
};
