<?php

use App\Services\CostoPromedio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 31/10/2026: costo promedio ponderado por insumo (uno para toda la empresa).
// - Costos con 6 decimales: antes la línea del movimiento guardaba solo 2 (un insumo de $0.0456
//   quedaba en $0.05 y uno de $0.0005 en $0.00).
// - supplies.last_purchase_cost: último precio de compra, solo de referencia.
// - Se calcula el promedio de los insumos que ya tienen compras.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->decimal('cost', 14, 6)->default(0)->change();
            $table->decimal('last_purchase_cost', 14, 6)->nullable()->after('cost');
        });
        Schema::table('inventory_movement_lines', function (Blueprint $table) {
            $table->decimal('unit_cost', 14, 6)->default(0)->change();
        });
        Schema::table('supply_price_history', function (Blueprint $table) {
            $table->decimal('old_cost', 14, 6)->nullable()->change();
            $table->decimal('new_cost', 14, 6)->change();
        });

        CostoPromedio::recalcular(DB::table('supplies')->pluck('id'));
    }

    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->dropColumn('last_purchase_cost');
            $table->decimal('cost', 12, 4)->default(0)->change();
        });
        Schema::table('inventory_movement_lines', function (Blueprint $table) {
            $table->decimal('unit_cost', 10, 2)->default(0)->change();
        });
        Schema::table('supply_price_history', function (Blueprint $table) {
            $table->decimal('old_cost', 12, 4)->nullable()->change();
            $table->decimal('new_cost', 12, 4)->change();
        });
    }
};
