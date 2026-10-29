<?php

// LIQUIDACIÓN AL PRODUCTOR (29/10/2026)
//
// Reglas acordadas con el usuario:
// - Se paga sobre las TARJAS (lo recibido en puerto): EMBARCADAS + CAÍDAS se pagan,
//   RECHAZADAS no. Ej.: 300 recibidas = 245 embarcadas + 5 caídas + 50 rechazadas → se pagan 250.
// - Cada compra es CONTRATO o SPOT y tiene su forma de pago: 100 % (normal en contrato)
//   u 80/20 (normal en spot: 80 % al embarcar, 20 % después).
// - Se descuentan: materiales retirados de categorías que se cobran al productor
//   (menos lo que devolvió a bodega) y anticipos/préstamos.
// - Liquidar deja el cupo LIQUIDADO (ya no se despacha ni se devuelve material contra él).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Tipo de compra y forma de pago en cada compra de Comercial
        Schema::table('producer_quota_lines', function (Blueprint $table) {
            $table->string('negotiation_type', 10)->default('SPOT');
            $table->string('payment_scheme', 10)->default('80_20');
        });
        DB::statement("ALTER TABLE producer_quota_lines ADD CONSTRAINT producer_quota_lines_negotiation_type_check CHECK (negotiation_type IN ('CONTRATO','SPOT'))");
        DB::statement("ALTER TABLE producer_quota_lines ADD CONSTRAINT producer_quota_lines_payment_scheme_check CHECK (payment_scheme IN ('100','80_20'))");

        // 2) Tarjas: lo que llega a puerto por camión, de una compra (productor + cliente + marca)
        Schema::create('tally_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producer_quota_line_id')->constrained('producer_quota_lines');
            $table->foreignId('shipment_id')->nullable()->constrained('shipments'); // barco
            $table->date('date');
            $table->string('number', 30)->nullable();      // N° de tarja
            $table->string('truck_plate', 15)->nullable();  // placa del camión
            $table->unsignedInteger('boxes_shipped')->default(0);   // embarcadas (se pagan)
            $table->unsignedInteger('boxes_fallen')->default(0);    // caídas (se pagan)
            $table->unsignedInteger('boxes_rejected')->default(0);  // rechazadas por calidad (NO se pagan)
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index('producer_quota_line_id');
        });

        // 3) Anticipos y préstamos al productor
        Schema::create('producer_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('third_party_id')->constrained('third_parties');
            $table->date('date');
            $table->string('type', 10)->default('ANTICIPO'); // ANTICIPO / PRESTAMO
            $table->decimal('amount', 12, 2);
            $table->string('concept', 255)->nullable();
            $table->string('reference', 60)->nullable(); // cheque / transferencia
            $table->boolean('status')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['third_party_id', 'status']);
        });
        DB::statement("ALTER TABLE producer_advances ADD CONSTRAINT producer_advances_type_check CHECK (type IN ('ANTICIPO','PRESTAMO'))");
        DB::statement('ALTER TABLE producer_advances ADD CONSTRAINT producer_advances_amount_check CHECK (amount > 0)');

        // 4) Liquidación: una por cupo (productor + semana)
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique(); // LIQ-00000001
            $table->foreignId('producer_quota_id')->constrained('producer_quotas');
            $table->foreignId('third_party_id')->constrained('third_parties');
            $table->unsignedSmallInteger('week');
            $table->unsignedSmallInteger('year');
            $table->date('date');
            $table->unsignedInteger('boxes_shipped')->default(0);
            $table->unsignedInteger('boxes_fallen')->default(0);
            $table->unsignedInteger('boxes_rejected')->default(0);
            $table->unsignedInteger('boxes_paid')->default(0);      // embarcadas + caídas
            $table->decimal('fruit_amount', 12, 2)->default(0);     // cajas pagadas × precio real
            $table->decimal('materials_amount', 12, 2)->default(0); // materiales netos de devoluciones
            $table->decimal('advances_amount', 12, 2)->default(0);  // anticipos/préstamos descontados
            $table->decimal('net_amount', 12, 2)->default(0);       // fruta − materiales − anticipos
            $table->string('notes', 500)->nullable();
            $table->boolean('status')->default(true);               // false = anulada
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['year', 'week']);
        });
        // Una sola liquidación ACTIVA por cupo (una anulada no impide liquidar de nuevo)
        DB::statement('CREATE UNIQUE INDEX settlements_cupo_activo_unique ON settlements (producer_quota_id) WHERE status');

        // Detalle: fruta por compra (cliente + marca)
        Schema::create('settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('settlements')->cascadeOnDelete();
            $table->foreignId('producer_quota_line_id')->constrained('producer_quota_lines');
            $table->string('negotiation_type', 10);
            $table->string('payment_scheme', 10);
            $table->unsignedInteger('boxes_purchased')->default(0);
            $table->unsignedInteger('boxes_shipped')->default(0);
            $table->unsignedInteger('boxes_fallen')->default(0);
            $table->unsignedInteger('boxes_rejected')->default(0);
            $table->unsignedInteger('boxes_paid')->default(0);
            $table->decimal('real_price', 10, 2);
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });

        // Detalle: materiales cobrados (retirado − devuelto) × costo promedio del retiro
        Schema::create('settlement_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('settlements')->cascadeOnDelete();
            $table->foreignId('supply_id')->constrained('supplies');
            $table->decimal('dispatched_qty', 12, 2);
            $table->decimal('returned_qty', 12, 2)->default(0);
            $table->decimal('charged_qty', 12, 2);
            $table->decimal('unit_cost', 12, 4);
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });

        // Detalle: anticipos descontados en esta liquidación
        Schema::create('settlement_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('settlements')->cascadeOnDelete();
            $table->foreignId('producer_advance_id')->constrained('producer_advances');
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });

        // Pagos: 1 (100 % o 80 %) y, si hay compras 80/20, 2 (20 %)
        Schema::create('settlement_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('settlements')->cascadeOnDelete();
            $table->unsignedTinyInteger('installment'); // 1 o 2
            $table->string('description', 60);
            $table->decimal('amount', 12, 2);
            $table->date('paid_at')->nullable();        // null = pendiente de pago
            $table->string('reference', 60)->nullable(); // comprobante / transferencia
            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['settlement_id', 'installment']);
        });

        // 5) CONTENEDORES no se cobra al productor (decisión del usuario 29/10/2026)
        DB::table('supply_categories')->where('name', 'CONTENEDORES')->update(['chargeable_to_producer' => false]);
    }

    public function down(): void
    {
        DB::table('supply_categories')->where('name', 'CONTENEDORES')->update(['chargeable_to_producer' => true]);
        Schema::dropIfExists('settlement_payments');
        Schema::dropIfExists('settlement_advances');
        Schema::dropIfExists('settlement_materials');
        Schema::dropIfExists('settlement_lines');
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('producer_advances');
        Schema::dropIfExists('tally_sheets');
        DB::statement('ALTER TABLE producer_quota_lines DROP CONSTRAINT IF EXISTS producer_quota_lines_negotiation_type_check');
        DB::statement('ALTER TABLE producer_quota_lines DROP CONSTRAINT IF EXISTS producer_quota_lines_payment_scheme_check');
        Schema::table('producer_quota_lines', function (Blueprint $table) {
            $table->dropColumn(['negotiation_type', 'payment_scheme']);
        });
    }
};
