<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 02/11/2026: GASTOS Y GANANCIA POR VIAJE (Finanzas)
//   - shipment_costs: tarifas de cada viaje (barco): flete por contenedor y flete total bajo cubierta
//   - shipment_expenses: otros gastos del viaje (puerto y trámites, transporte, termógrafos y sellos,
//     seguro, comisiones, otros), de todo el viaje o de un cliente
//   - tipo base FINANZAS con su perfil; GERENCIA también ve la ganancia por viaje
return new class extends Migration
{
    private const CATEGORIAS = "'PUERTO_TRAMITES','TRANSPORTE','TERMOGRAFOS_SELLOS','SEGURO','COMISION','OTRO'";
    private const ROLES_ANTES = "'ADMIN','JEFE_BODEGA','COORDINADOR_INVENTARIO','BODEGUERO','EXPORTACIONES','COMERCIAL','GERENCIA'";

    public function up(): void
    {
        Schema::create('shipment_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->unique()->constrained('shipments');
            $table->decimal('container_rate', 12, 2)->nullable();    // lo que cobra la nave por contenedor (CFR/CIF)
            $table->decimal('under_deck_amount', 12, 2)->nullable(); // flete total de la carga bajo cubierta
            $table->string('notes', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('shipment_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments');
            $table->foreignId('customer_id')->nullable()->constrained('customers'); // NULL = de todo el viaje
            $table->string('category', 30);
            $table->string('description', 150)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('third_parties');
            $table->string('invoice_number', 40)->nullable();
            $table->date('invoice_date')->nullable();
            $table->decimal('amount', 12, 2);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index('shipment_id');
        });
        DB::statement('ALTER TABLE shipment_expenses ADD CONSTRAINT shipment_expenses_category_check CHECK (category IN (' . self::CATEGORIAS . '))');
        DB::statement('ALTER TABLE shipment_expenses ADD CONSTRAINT shipment_expenses_amount_check CHECK (amount >= 0)');

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN (' . self::ROLES_ANTES . ",'FINANZAS'))");

        if (!DB::table('profiles')->where('name', 'FINANZAS')->exists()) {
            DB::table('profiles')->insert([
                'name' => 'FINANZAS', 'base_role' => 'FINANZAS', 'description' => 'Gastos de cada viaje y ganancia por viaje.',
                'screens' => json_encode(['gastos_viaje', 'ganancia_viaje']), 'status' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (DB::table('profiles')->where('base_role', 'GERENCIA')->get(['id', 'screens']) as $p) {
            $pantallas = json_decode($p->screens ?? '[]', true) ?: [];
            if (!in_array('ganancia_viaje', $pantallas)) {
                DB::table('profiles')->where('id', $p->id)->update(['screens' => json_encode([...$pantallas, 'ganancia_viaje'])]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('profiles')->get(['id', 'screens']) as $p) {
            $pantallas = json_decode($p->screens ?? '[]', true) ?: [];
            DB::table('profiles')->where('id', $p->id)->update(['screens' => json_encode(array_values(array_diff($pantallas, ['gastos_viaje', 'ganancia_viaje'])))]);
        }
        DB::table('profiles')->where('base_role', 'FINANZAS')->whereNotExists(
            fn ($q) => $q->select(DB::raw(1))->from('users')->whereColumn('users.profile_id', 'profiles.id')
        )->delete();
        if (!DB::table('users')->where('role', 'FINANZAS')->exists()) {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
            DB::statement('ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN (' . self::ROLES_ANTES . '))');
        }
        Schema::dropIfExists('shipment_expenses');
        Schema::dropIfExists('shipment_costs');
    }
};
