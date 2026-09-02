<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            // % de IVA aplicado a esta línea: 15.00, 5.00 o 0.00
            $table->decimal('tax_rate', 5, 2)->default(15.00)->after('unit_price');
            // % de descuento sobre el subtotal de la línea (ej. 10.00 = 10%)
            $table->decimal('discount_percent', 5, 2)->default(0)->after('tax_rate');
            // % de Retención IR aplicado a esta línea (varía por tipo de bien/servicio)
            $table->decimal('retention_rate', 5, 2)->default(0)->after('discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->dropColumn(['tax_rate', 'discount_percent', 'retention_rate']);
        });
    }
};