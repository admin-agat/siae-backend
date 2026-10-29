<?php

// FASE 3 del flujo de pedidos (28/10/2026): Comercial compra la fruta.
// Con el total de cajas de la semana (ya distribuido por Logística), Comercial reparte la
// compra entre productores y comercializadoras: productor + cliente + marca + cajas +
// PRECIO OFICIAL (el del ministerio, ej. 7,50) + PRECIO REAL (el pagado, ej. 7,10).
//
// Se reutiliza el cupo semanal que ya usaba el Despacho de materiales:
//   producer_quotas      (uno por productor + semana)  + customer_order_id
//   producer_quota_lines (cajas por marca)             + customer_id, brand_id, official_price, real_price
// Las tablas estaban vacías.
//
// El UNIQUE (cupo, marca) pasa a (cupo, marca, cliente): un productor puede vender
// DOÑA ELENA para GT y también para RVI la misma semana (dos líneas de la misma marca).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('producer_quotas', function (Blueprint $table) {
            $table->foreignId('customer_order_id')->nullable()->constrained('customer_orders');
        });

        Schema::table('producer_quota_lines', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->foreignId('brand_id')->nullable()->constrained('brands');
            $table->decimal('official_price', 10, 2)->nullable(); // precio oficial por caja (ministerio)
            $table->decimal('real_price', 10, 2)->nullable();     // precio real pagado al productor
        });

        DB::statement('ALTER TABLE producer_quota_lines DROP CONSTRAINT producer_quota_lines_cupo_marca_unique');
        DB::statement('ALTER TABLE producer_quota_lines ADD CONSTRAINT producer_quota_lines_cupo_marca_cliente_unique
            UNIQUE (producer_quota_id, variety, customer_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE producer_quota_lines DROP CONSTRAINT IF EXISTS producer_quota_lines_cupo_marca_cliente_unique');
        DB::statement('ALTER TABLE producer_quota_lines ADD CONSTRAINT producer_quota_lines_cupo_marca_unique UNIQUE (producer_quota_id, variety)');

        Schema::table('producer_quota_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropConstrainedForeignId('brand_id');
            $table->dropColumn(['official_price', 'real_price']);
        });

        Schema::table('producer_quotas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_order_id');
        });
    }
};
