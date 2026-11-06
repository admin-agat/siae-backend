<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 06/11/2026: LIQUIDACIÓN COMO EL EXCEL "Liquidaciones wk 4x - AUTOMATICO" — parte 1 (datos base)
//   - Parámetros por semana (hoja PARAMETROS): mínimo oficial por caja, tipo de caja, tasa BPA y
//     reconocimiento por caja en Puerto Bolívar.
//   - Tabla de tramos de la retención IR único banano (hoja CALCULADORA BANANERA, Art. 27 LRTI).
//   - Régimen de retención por tercero (NORMAL / BPA / NO_RETENER) y nombre corto para la
//     referencia de pago (hoja Base Productores, columnas K y R).
//   - Contratos del productor (hoja Base Productores): N° (1, 2…), cupo semanal, precio, condición
//     y si se le cobra transferencia, evaluadores, materiales y transporte. Se usa la tabla
//     `contracts` (MAGAP), que estaba vacía y sin pantalla.
//   - Cada compra de CONTRATO dice qué contrato es y qué parte (CONTRATO 1 = 0, 1.1 = 1, 1.2 = 2…).
//   - Tipo base LIQUIDACIONES ("Departamento de Liquidaciones") con su perfil.
return new class extends Migration
{
    private const ROLES_ANTES = "'ADMIN','JEFE_BODEGA','COORDINADOR_INVENTARIO','BODEGUERO','EXPORTACIONES','COMERCIAL','GERENCIA','FINANZAS','CONTABILIDAD','DEPARTAMENTO'";

    public function up(): void
    {
        Schema::create('settlement_week_parameters', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('week');
            $table->decimal('minimum_price', 8, 2);                    // mínimo oficial por caja (ej. 7,50)
            $table->string('box_type', 20)->default('22XU');          // se imprime en el encabezado
            $table->decimal('bpa_rate', 6, 4)->default(0.01);          // retención fija con BPA vigente
            $table->decimal('pto_bolivar_bonus', 8, 2)->default(0.30); // reconocimiento por caja en Pto. Bolívar
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['year', 'week']);
        });

        Schema::create('retention_brackets', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('from_boxes');           // "más de" (exclusivo)
            $table->unsignedInteger('to_boxes')->nullable(); // "hasta" (inclusivo); null = en adelante
            $table->decimal('rate', 6, 4);
            $table->timestamps();
        });
        $tramos = [[0, 1000, 0.01], [1000, 5000, 0.0125], [5000, 20000, 0.015], [20000, 50000, 0.0175], [50000, null, 0.02]];
        DB::table('retention_brackets')->insert(array_map(fn ($t) => [
            'from_boxes' => $t[0], 'to_boxes' => $t[1], 'rate' => $t[2], 'created_at' => now(), 'updated_at' => now(),
        ], $tramos));

        Schema::table('third_parties', function (Blueprint $table) {
            $table->string('retention_regime', 12)->default('NORMAL'); // NORMAL | BPA | NO_RETENER
            $table->string('payment_short_name', 40)->nullable();      // "JEHOVA JIREH" en la referencia del banco
        });
        DB::statement("ALTER TABLE third_parties ADD CONSTRAINT tp_retention_regime_check CHECK (retention_regime IN ('NORMAL','BPA','NO_RETENER'))");
        // Asociaciones y cooperativas no están sujetas a retención (en el Excel vienen precargadas así)
        DB::statement("UPDATE third_parties SET retention_regime = 'NO_RETENER' WHERE name ~* '(^|\\s)(ASOC|ASOCIACI|COOP|COOPERATIV|ASOPRO)'");

        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedSmallInteger('number')->default(1);          // CONTRATO 1, 2…
            $table->decimal('price', 8, 2)->nullable();                 // precio pactado del contrato
            $table->unsignedInteger('weekly_boxes')->default(0);        // cupo semanal de cajas
            $table->string('discount_condition', 15)->nullable();       // CON_DESCUENTO | SIN_DESCUENTO (referencia)
            $table->boolean('charge_transfer')->default(true);
            $table->boolean('charge_evaluators')->default(true);
            $table->boolean('charge_materials')->default(true);
            $table->boolean('charge_transport')->default(true);
            $table->string('notes', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');
        });
        DB::statement("ALTER TABLE contracts ADD CONSTRAINT contracts_discount_condition_check CHECK (discount_condition IS NULL OR discount_condition IN ('CON_DESCUENTO','SIN_DESCUENTO'))");
        // Un solo contrato ACTIVO con el mismo número por tercero
        DB::statement('CREATE UNIQUE INDEX contracts_tercero_numero_activo ON contracts (third_party_id, number) WHERE status');

        Schema::table('producer_quota_lines', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->constrained('contracts'); // solo compras de CONTRATO
            $table->unsignedSmallInteger('contract_part')->default(0);              // 0 = CONTRATO 1, 1 = 1.1, 2 = 1.2…
        });

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN (' . self::ROLES_ANTES . ",'LIQUIDACIONES'))");
        if (!DB::table('profiles')->where('name', 'LIQUIDACIONES')->exists()) {
            DB::table('profiles')->insert([
                'name' => 'LIQUIDACIONES', 'base_role' => 'LIQUIDACIONES',
                'description' => 'Departamento de Liquidaciones: contratos y retención de productores, parámetros de la semana, tarjas, liquidaciones y pagos.',
                'screens' => json_encode(['parametros_liquidacion', 'contratos_productores', 'tarjas', 'anticipos', 'liquidar', 'pagos', 'notas_credito']),
                'status' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('profiles')->where('base_role', 'LIQUIDACIONES')->whereNotExists(
            fn ($q) => $q->select(DB::raw(1))->from('users')->whereColumn('users.profile_id', 'profiles.id')
        )->delete();
        if (!DB::table('users')->where('role', 'LIQUIDACIONES')->exists()) {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
            DB::statement('ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN (' . self::ROLES_ANTES . '))');
        }

        Schema::table('producer_quota_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_id');
            $table->dropColumn('contract_part');
        });

        DB::statement('DROP INDEX IF EXISTS contracts_tercero_numero_activo');
        DB::statement('ALTER TABLE contracts DROP CONSTRAINT IF EXISTS contracts_discount_condition_check');
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['number', 'price', 'weekly_boxes', 'discount_condition', 'charge_transfer',
                'charge_evaluators', 'charge_materials', 'charge_transport', 'notes']);
        });

        DB::statement('ALTER TABLE third_parties DROP CONSTRAINT IF EXISTS tp_retention_regime_check');
        Schema::table('third_parties', fn (Blueprint $table) => $table->dropColumn(['retention_regime', 'payment_short_name']));

        Schema::dropIfExists('retention_brackets');
        Schema::dropIfExists('settlement_week_parameters');
    }
};
