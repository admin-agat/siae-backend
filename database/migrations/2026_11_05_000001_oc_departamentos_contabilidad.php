<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 05/11/2026: ÓRDENES DE COMPRA POR DEPARTAMENTO + APROBACIÓN Y PAGO EN CONTABILIDAD
//   - Cada departamento (Compras, Sistemas, Asistente de Gerencia, Logística...) tiene su usuario de
//     tipo DEPARTAMENTO (users.department) y llena sus OC: líneas de texto libre, sin insumo ni bodega.
//   - Contabilidad (tipo CONTABILIDAD) ve las OC de todos (también las de bodega), aprueba o rechaza
//     (solo ella aprueba) y arma la carga masiva al banco: un lote (bank_payment_batches) = un TXT.
//   - Cuentas bancarias de proveedores en su propia tabla (no salen en /third-parties).
//   - Las OC que ya existían quedan como ANTERIOR: no aparecen por aprobar ni por pagar (pueden estar
//     pagadas por fuera del sistema).
return new class extends Migration
{
    private const ROLES_ANTES = "'ADMIN','JEFE_BODEGA','COORDINADOR_INVENTARIO','BODEGUERO','EXPORTACIONES','COMERCIAL','GERENCIA','FINANZAS'";

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('department', 60)->nullable()->after('role'); // SISTEMAS, LOGÍSTICA... (la OC lo copia)
        });
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN (' . self::ROLES_ANTES . ",'CONTABILIDAD','DEPARTAMENTO'))");

        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('code', 10)->nullable(); // código del banco en el archivo de Banco Pichincha (lo llena Contabilidad)
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        $bancos = ['BANCO PICHINCHA', 'BANCO DE GUAYAQUIL', 'BANCO DEL PACÍFICO', 'PRODUBANCO', 'BANCO INTERNACIONAL',
            'BANCO BOLIVARIANO', 'BANCO DEL AUSTRO', 'BANCO DE MACHALA', 'BANCO GENERAL RUMIÑAHUI', 'BANCO DE LOJA',
            'BANCO SOLIDARIO', 'BANCO PROCREDIT', 'BANCO AMAZONAS', 'BANCO CAPITAL', 'BANECUADOR', 'BANCO DINERS CLUB',
            'COOPERATIVA JEP', 'COOPERATIVA JARDÍN AZUAYO', 'COOPERATIVA POLICÍA NACIONAL', 'COOPERATIVA 29 DE OCTUBRE'];
        DB::table('banks')->insert(array_map(fn ($n) => ['name' => $n, 'status' => true, 'created_at' => now(), 'updated_at' => now()], $bancos));

        Schema::create('third_party_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('third_party_id')->unique()->constrained('third_parties');
            $table->foreignId('bank_id')->constrained('banks');
            $table->string('account_type', 10);    // AHORROS | CORRIENTE
            $table->string('account_number', 30);
            $table->string('holder_id_type', 1);   // C cédula | R RUC | P pasaporte
            $table->string('holder_id', 20);
            $table->string('holder_name', 120);
            $table->string('email', 120)->nullable(); // aviso del pago al proveedor
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });
        DB::statement("ALTER TABLE third_party_bank_accounts ADD CONSTRAINT tpba_account_type_check CHECK (account_type IN ('AHORROS','CORRIENTE'))");
        DB::statement("ALTER TABLE third_party_bank_accounts ADD CONSTRAINT tpba_holder_id_type_check CHECK (holder_id_type IN ('C','R','P'))");

        Schema::create('bank_payment_batches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique(); // PAG-2026-0001
            $table->date('date');
            $table->unsignedInteger('payments_count');
            $table->decimal('total', 14, 2);
            $table->string('file_name', 80);
            $table->text('content');              // el TXT tal como se descargó (para volver a bajarlo igual)
            $table->string('status', 15)->default('GENERADO'); // GENERADO | PAGADO | ANULADO
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE bank_payment_batches ADD CONSTRAINT bpb_status_check CHECK (status IN ('GENERADO','PAGADO','ANULADO'))");

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('department', 60)->nullable()->after('warehouse_id');
            $table->string('approval_status', 15)->default('POR_APROBAR')->after('status'); // POR_APROBAR | APROBADA | RECHAZADA | ANTERIOR
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('payment_status', 15)->nullable(); // null = sin pagar | EN_BANCO | PAGADA
            $table->foreignId('bank_payment_batch_id')->nullable()->constrained('bank_payment_batches');
            $table->index(['approval_status', 'payment_status']);
        });
        // OC de departamento: no entra a bodega
        DB::statement('ALTER TABLE purchase_orders ALTER COLUMN warehouse_id DROP NOT NULL');
        DB::table('purchase_orders')->update(['department' => 'BODEGA', 'approval_status' => 'ANTERIOR']);
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT po_approval_status_check CHECK (approval_status IN ('POR_APROBAR','APROBADA','RECHAZADA','ANTERIOR'))");
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT po_payment_status_check CHECK (payment_status IS NULL OR payment_status IN ('EN_BANCO','PAGADA'))");

        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->string('description', 255)->nullable()->after('supply_id'); // línea de texto libre (OC de departamento)
        });
        DB::statement('ALTER TABLE purchase_order_lines ALTER COLUMN supply_id DROP NOT NULL');
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT pol_insumo_o_descripcion_check CHECK (supply_id IS NOT NULL OR description IS NOT NULL)');

        $perfiles = [
            ['CONTABILIDAD', 'CONTABILIDAD', 'Aprueba las OC de todos los departamentos y genera el archivo de pagos para el banco.', ['aprobar_oc', 'cuentas_bancarias']],
            ['DEPARTAMENTO', 'DEPARTAMENTO', 'Usuario de un departamento: llena sus órdenes de compra y ve si Contabilidad las aprobó.', ['oc_departamento']],
        ];
        foreach ($perfiles as [$nombre, $rol, $descripcion, $pantallas]) {
            if (!DB::table('profiles')->where('name', $nombre)->exists()) {
                DB::table('profiles')->insert([
                    'name' => $nombre, 'base_role' => $rol, 'description' => $descripcion,
                    'screens' => json_encode($pantallas), 'status' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('profiles')->whereIn('base_role', ['CONTABILIDAD', 'DEPARTAMENTO'])->whereNotExists(
            fn ($q) => $q->select(DB::raw(1))->from('users')->whereColumn('users.profile_id', 'profiles.id')
        )->delete();

        DB::statement('ALTER TABLE purchase_order_lines DROP CONSTRAINT IF EXISTS pol_insumo_o_descripcion_check');
        Schema::table('purchase_order_lines', fn (Blueprint $table) => $table->dropColumn('description'));

        DB::statement('ALTER TABLE purchase_orders DROP CONSTRAINT IF EXISTS po_approval_status_check');
        DB::statement('ALTER TABLE purchase_orders DROP CONSTRAINT IF EXISTS po_payment_status_check');
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex(['approval_status', 'payment_status']);
            $table->dropConstrainedForeignId('bank_payment_batch_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['department', 'approval_status', 'approved_at', 'rejection_reason', 'payment_status']);
        });

        Schema::dropIfExists('bank_payment_batches');
        Schema::dropIfExists('third_party_bank_accounts');
        Schema::dropIfExists('banks');

        if (!DB::table('users')->whereIn('role', ['CONTABILIDAD', 'DEPARTAMENTO'])->exists()) {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
            DB::statement('ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN (' . self::ROLES_ANTES . '))');
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('department'));
        // warehouse_id / supply_id vuelven a NOT NULL solo si no hay OC de departamento (se dejan nullable)
    }
};
