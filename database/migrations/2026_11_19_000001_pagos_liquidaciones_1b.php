<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 19/11/2026: PAGOS DE LIQUIDACIONES → CONTABILIDAD (fase 1b de docs/modulos/pagos-liquidaciones.md, 7.3 + 13.A.4).
//   - SPOT 80/20: Comercial pulsa PAGAR en la compra enviada a bodega → anticipo origin SPOT_80 sobre las CAJAS
//     COMPRADAS × precio pactado × 0,80; la LQ SPOT lo descuenta (settlements.advance_80_amount). Si sobra,
//     SALDO A FAVOR DE AGAT (origin SALDO).
//   - CONTRATO: una cuota del 100 %; semana de pago = N+1 + payment_week_offset (0/1).
//   - Anticipos y préstamos manuales también pasan por aprobación y TXT (P14).
//   - Aprobación (jefe de Liquidaciones) y pago (Contabilidad) como las OC; lotes con líneas y cuenta congelada.
//   - La retención IR ya está en settlements desde 2026_11_17_000001_liquidacion_fase0.
return new class extends Migration
{
    public function up(): void
    {
        // 1) Contrato: pagar en la semana siguiente a la compra (0) o una más (1). Lo pone LEGAL.
        Schema::table('contracts', function (Blueprint $table) {
            $table->smallInteger('payment_week_offset')->default(0);
        });
        DB::statement('ALTER TABLE contracts ADD CONSTRAINT contracts_payment_week_offset_check CHECK (payment_week_offset IN (0,1))');

        // 2) Correlativo de órdenes de pago ORD-AAAA-NNNNN (compartido por anticipos y cuotas; seguro con concurrencia)
        DB::statement('CREATE SEQUENCE IF NOT EXISTS payment_order_seq');

        // 3) LQ SPOT: 80 % descontado, a la vista como en el Excel
        Schema::table('settlements', function (Blueprint $table) {
            $table->decimal('advance_80_amount', 12, 2)->default(0)->after('advances_amount');
        });
        DB::statement('ALTER TABLE settlements ADD CONSTRAINT settlements_advance_80_check CHECK (advance_80_amount >= 0)');

        // 4) Anticipos: 80 % pedido por Comercial (SPOT_80), saldo a favor y manuales, con aprobación y pago
        Schema::table('producer_advances', function (Blueprint $table) {
            // 19/11/2026: Compra de fruta borra de verdad la compra y el cupo que se quitan (solo con la orden ANULADA):
            // la orden anulada queda en la bitácora sin compra (nullOnDelete)
            $table->foreignId('producer_quota_id')->nullable()->constrained('producer_quotas')->nullOnDelete();
            $table->foreignId('producer_quota_line_id')->nullable()->constrained('producer_quota_lines')->nullOnDelete(); // compra del 80 %
            $table->foreignId('source_settlement_id')->nullable()->constrained('settlements');           // LQ que dejó el SALDO
            $table->string('payment_order_code', 20)->nullable()->unique();
            $table->string('origin', 10)->default('MANUAL');      // MANUAL | SPOT_80 | SALDO
            // 19/11/2026: el 80 % se calcula con las CAJAS COMPRADAS (no con la tarja) y el precio pactado,
            // congelados al pulsar PAGAR. Se recalculan solo mientras la orden no salga al banco (13.A.3).
            $table->integer('boxes')->nullable();                 // cajas compradas al pedir el 80 %
            $table->decimal('unit_price', 12, 4)->nullable();     // precio pactado (real_price) al pedir el 80 %
            $table->smallInteger('scheduled_week')->nullable();   // 19/11/2026: semana ISO del día de corte
            $table->smallInteger('scheduled_year')->nullable();
            $table->string('approval_status', 15)->default('POR_APROBAR');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('payment_status', 10)->nullable();     // null | EN_BANCO | PAGADA | FALLIDA
            $table->string('payment_method', 15)->nullable();     // LOTE_BANCO | TRANSFERENCIA | CHEQUE | CRUCE
            $table->date('paid_at')->nullable();
            $table->string('payment_reference', 60)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->string('voucher_path', 255)->nullable();
            $table->index(['approval_status', 'payment_status']);
        });
        // Los anticipos que ya existían se entregaron por fuera: ANTERIOR + PAGADA (no entran a bandejas y se siguen descontando)
        DB::table('producer_advances')->update(['approval_status' => 'ANTERIOR', 'payment_status' => 'PAGADA']);
        DB::statement("ALTER TABLE producer_advances ADD CONSTRAINT pa_origin_check CHECK (origin IN ('MANUAL','SPOT_80','SALDO'))");
        DB::statement("ALTER TABLE producer_advances ADD CONSTRAINT pa_approval_status_check CHECK (approval_status IN ('POR_APROBAR','APROBADA','RECHAZADA','ANULADA','ANTERIOR'))");
        DB::statement("ALTER TABLE producer_advances ADD CONSTRAINT pa_payment_status_check CHECK (payment_status IS NULL OR payment_status IN ('EN_BANCO','PAGADA','FALLIDA'))");
        DB::statement("ALTER TABLE producer_advances ADD CONSTRAINT pa_payment_method_check CHECK (payment_method IS NULL OR payment_method IN ('LOTE_BANCO','TRANSFERENCIA','CHEQUE','CRUCE'))");
        // 19/11/2026: el 80 % activo siempre lleva compra, cupo, cajas, precio y semana de pago (el anulado puede perder la compra)
        DB::statement("ALTER TABLE producer_advances ADD CONSTRAINT pa_spot80_check CHECK (origin <> 'SPOT_80' OR NOT status OR (producer_quota_id IS NOT NULL
            AND producer_quota_line_id IS NOT NULL AND boxes > 0 AND unit_price > 0 AND scheduled_week IS NOT NULL AND scheduled_year IS NOT NULL))");
        DB::statement("ALTER TABLE producer_advances ADD CONSTRAINT pa_saldo_check CHECK (origin <> 'SALDO' OR source_settlement_id IS NOT NULL)");
        DB::statement("ALTER TABLE producer_advances ADD CONSTRAINT pa_pago_aprobado_check CHECK (payment_status IS NULL OR approval_status IN ('APROBADA','ANTERIOR'))");
        DB::statement("CREATE UNIQUE INDEX producer_advances_spot80_activo_unique ON producer_advances (producer_quota_line_id) WHERE origin = 'SPOT_80' AND status");
        Schema::table('producer_quota_lines', fn (Blueprint $table) => $table->index('cut_date'));

        // 5) Cuotas de liquidación: aprobación, semana programada, beneficiario y pago
        Schema::table('settlement_payments', function (Blueprint $table) {
            $table->string('payment_order_code', 20)->nullable()->unique();
            $table->string('approval_status', 15)->default('POR_APROBAR');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->smallInteger('scheduled_week')->nullable();
            $table->smallInteger('scheduled_year')->nullable();
            $table->foreignId('payee_third_party_id')->nullable()->constrained('third_parties'); // null = el vendedor
            $table->string('payment_status', 10)->nullable();
            $table->string('payment_method', 15)->nullable();
            $table->string('voucher_path', 255)->nullable();
            $table->index(['approval_status', 'payment_status']);
        });
        DB::table('settlement_payments')->whereNotNull('paid_at')
            ->update(['approval_status' => 'ANTERIOR', 'payment_status' => 'PAGADA', 'payment_method' => 'TRANSFERENCIA']);
        DB::statement("ALTER TABLE settlement_payments ADD CONSTRAINT sp_approval_status_check CHECK (approval_status IN ('POR_APROBAR','APROBADA','RECHAZADA','ANULADA','ANTERIOR'))");
        DB::statement("ALTER TABLE settlement_payments ADD CONSTRAINT sp_payment_status_check CHECK (payment_status IS NULL OR payment_status IN ('EN_BANCO','PAGADA','FALLIDA'))");
        DB::statement("ALTER TABLE settlement_payments ADD CONSTRAINT sp_payment_method_check CHECK (payment_method IS NULL OR payment_method IN ('LOTE_BANCO','TRANSFERENCIA','CHEQUE','CRUCE'))");
        DB::statement("ALTER TABLE settlement_payments ADD CONSTRAINT sp_pago_aprobado_check CHECK (payment_status IS NULL OR approval_status IN ('APROBADA','ANTERIOR'))");

        // 6) Líneas del lote al banco (una por orden, P20) con la cuenta congelada y cifrada
        Schema::create('bank_payment_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_payment_batch_id')->constrained('bank_payment_batches');
            $table->string('source_type', 12);                    // OC | LIQUIDACION | ANTICIPO
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders');
            $table->foreignId('settlement_payment_id')->nullable()->constrained('settlement_payments');
            $table->foreignId('producer_advance_id')->nullable()->constrained('producer_advances');
            $table->foreignId('payee_third_party_id')->nullable()->constrained('third_parties');
            $table->decimal('amount', 12, 2);
            $table->foreignId('bank_id')->nullable()->constrained('banks'); // null solo en líneas históricas de OC
            $table->string('account_type', 10)->nullable();
            $table->text('account_number')->nullable();           // cifrado (cast 'encrypted')
            $table->string('holder_id_type', 1)->nullable();
            $table->text('holder_id')->nullable();                // cifrado (cédula o RUC)
            $table->string('holder_name', 120)->nullable();
            $table->string('reference', 40)->nullable();
            $table->string('status', 10)->default('EN_BANCO');    // EN_BANCO | PAGADO | FALLIDO | ANULADO
            $table->text('failure_reason')->nullable();
            $table->date('paid_at')->nullable();
            $table->string('bank_reference', 60)->nullable();
            $table->timestamps();
            $table->index('bank_payment_batch_id');
        });
        DB::statement("ALTER TABLE bank_payment_batch_items ADD CONSTRAINT bpbi_status_check CHECK (status IN ('EN_BANCO','PAGADO','FALLIDO','ANULADO'))");
        DB::statement("ALTER TABLE bank_payment_batch_items ADD CONSTRAINT bpbi_source_type_check CHECK (source_type IN ('OC','LIQUIDACION','ANTICIPO'))");
        DB::statement("ALTER TABLE bank_payment_batch_items ADD CONSTRAINT bpbi_amount_check CHECK (amount > 0)");
        DB::statement("ALTER TABLE bank_payment_batch_items ADD CONSTRAINT bpbi_source_check CHECK (
            (source_type = 'OC'          AND purchase_order_id IS NOT NULL AND settlement_payment_id IS NULL AND producer_advance_id IS NULL) OR
            (source_type = 'LIQUIDACION' AND settlement_payment_id IS NOT NULL AND purchase_order_id IS NULL AND producer_advance_id IS NULL) OR
            (source_type = 'ANTICIPO'    AND producer_advance_id IS NOT NULL AND purchase_order_id IS NULL AND settlement_payment_id IS NULL))");
        // Candado contra el doble pago: un mismo origen no puede estar en dos líneas vivas
        foreach (['purchase_order_id', 'settlement_payment_id', 'producer_advance_id'] as $col) {
            DB::statement("CREATE UNIQUE INDEX bpbi_{$col}_vivo_unique ON bank_payment_batch_items ({$col}) WHERE status IN ('EN_BANCO','PAGADO')");
        }
        // Las OC que ya están en lotes pasan a líneas (sin copia de cuenta: no se descifra en una migración).
        // En producción hay 0 lotes (17/11); se deja por si cambia antes de subir.
        // 19/11/2026: el total de la OC es un accesor del modelo (subtotal + IVA − retención), no una columna:
        // se calcula con PurchaseOrder::total.
        foreach (\App\Models\PurchaseOrder::with(['lines', 'bankPaymentBatch'])->whereNotNull('bank_payment_batch_id')->get() as $po) {
            DB::table('bank_payment_batch_items')->insert([
                'bank_payment_batch_id' => $po->bank_payment_batch_id,
                'source_type'           => 'OC',
                'purchase_order_id'     => $po->id,
                'payee_third_party_id'  => $po->third_party_id,
                'amount'                => max((float) $po->total, 0.01),
                'status'                => match ($po->bankPaymentBatch?->status) { 'PAGADO' => 'PAGADO', 'ANULADO' => 'ANULADO', default => 'EN_BANCO' },
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);
        }

        // 7) Lote pagado en parte
        DB::statement('ALTER TABLE bank_payment_batches DROP CONSTRAINT IF EXISTS bpb_status_check');
        DB::statement("ALTER TABLE bank_payment_batches ADD CONSTRAINT bpb_status_check CHECK (status IN ('GENERADO','PAGADO','PAGADO_PARCIAL','ANULADO'))");

        // 8) Perfiles: pantallas nuevas (no se le quita nada a nadie).
        //    aprobar_pagos_liquidacion NO va al perfil LIQUIDACIONES: es solo del jefe, por excepción (P3).
        //    19/11/2026: pagar_anticipo_80 NO va en ningún perfil: el ADMIN se la da a la asistente comercial (13.A.4).
        $this->agregarPantalla('CONTABILIDAD', 'pagos_banco');
        $this->agregarPantalla('CONTABILIDAD', 'confirmar_pagos');
        $this->agregarPantalla('GERENCIA', 'tablero_pagos_lectura');
        $this->agregarPantalla('FINANZAS', 'tablero_pagos_lectura');
    }

    public function down(): void
    {
        foreach ([['CONTABILIDAD', 'pagos_banco'], ['CONTABILIDAD', 'confirmar_pagos'],
                  ['GERENCIA', 'tablero_pagos_lectura'], ['FINANZAS', 'tablero_pagos_lectura']] as [$perfil, $clave]) {
            $this->quitarPantalla($perfil, $clave);
        }

        DB::statement('ALTER TABLE bank_payment_batches DROP CONSTRAINT IF EXISTS bpb_status_check');
        DB::table('bank_payment_batches')->where('status', 'PAGADO_PARCIAL')->update(['status' => 'PAGADO']);
        DB::statement("ALTER TABLE bank_payment_batches ADD CONSTRAINT bpb_status_check CHECK (status IN ('GENERADO','PAGADO','ANULADO'))");

        Schema::dropIfExists('bank_payment_batch_items');

        foreach (['sp_approval_status_check', 'sp_payment_status_check', 'sp_payment_method_check', 'sp_pago_aprobado_check'] as $c) {
            DB::statement("ALTER TABLE settlement_payments DROP CONSTRAINT IF EXISTS {$c}");
        }
        Schema::table('settlement_payments', function (Blueprint $table) {
            $table->dropIndex(['approval_status', 'payment_status']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('payee_third_party_id');
            $table->dropUnique(['payment_order_code']);
            $table->dropColumn(['payment_order_code', 'approval_status', 'approved_at', 'rejection_reason', 'scheduled_week',
                'scheduled_year', 'payment_status', 'payment_method', 'voucher_path']);
        });

        DB::statement('DROP INDEX IF EXISTS producer_advances_spot80_activo_unique');
        Schema::table('producer_quota_lines', fn (Blueprint $table) => $table->dropIndex(['cut_date']));
        foreach (['pa_origin_check', 'pa_approval_status_check', 'pa_payment_status_check', 'pa_payment_method_check',
                  'pa_spot80_check', 'pa_saldo_check', 'pa_pago_aprobado_check'] as $c) {
            DB::statement("ALTER TABLE producer_advances DROP CONSTRAINT IF EXISTS {$c}");
        }
        // Los 80 % y los saldos a favor no tienen sentido sin estas columnas: se desactivan (no se borran)
        DB::table('producer_advances')->where('origin', '<>', 'MANUAL')->update(['status' => false]);
        Schema::table('producer_advances', function (Blueprint $table) {
            $table->dropIndex(['approval_status', 'payment_status']);
            foreach (['producer_quota_id', 'producer_quota_line_id', 'source_settlement_id', 'approved_by', 'paid_by'] as $fk) {
                $table->dropConstrainedForeignId($fk);
            }
            $table->dropUnique(['payment_order_code']);
            $table->dropColumn(['payment_order_code', 'origin', 'boxes', 'unit_price', 'scheduled_week', 'scheduled_year', 'approval_status',
                'approved_at', 'rejection_reason', 'payment_status', 'payment_method', 'paid_at', 'payment_reference', 'voucher_path']);
        });

        DB::statement('ALTER TABLE settlements DROP CONSTRAINT IF EXISTS settlements_advance_80_check');
        Schema::table('settlements', fn (Blueprint $table) => $table->dropColumn('advance_80_amount'));
        DB::statement('DROP SEQUENCE IF EXISTS payment_order_seq');
        DB::statement('ALTER TABLE contracts DROP CONSTRAINT IF EXISTS contracts_payment_week_offset_check');
        Schema::table('contracts', fn (Blueprint $table) => $table->dropColumn('payment_week_offset'));
    }

    // profiles.screens es jsonb: llega como texto y se guarda como JSON
    private function agregarPantalla(string $perfil, string $clave): void
    {
        foreach (DB::table('profiles')->where('name', $perfil)->where('base_role', $perfil)->get() as $p) {
            $pantallas = json_decode($p->screens ?? '[]', true) ?: [];
            if (!in_array($clave, $pantallas, true)) {
                $pantallas[] = $clave;
                DB::table('profiles')->where('id', $p->id)->update(['screens' => json_encode($pantallas)]);
            }
        }
    }

    private function quitarPantalla(string $perfil, string $clave): void
    {
        foreach (DB::table('profiles')->where('name', $perfil)->where('base_role', $perfil)->get() as $p) {
            $pantallas = array_values(array_diff(json_decode($p->screens ?? '[]', true) ?: [], [$clave]));
            DB::table('profiles')->where('id', $p->id)->update(['screens' => json_encode($pantallas)]);
        }
    }
};
