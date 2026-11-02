<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 02/11/2026: el precio de venta depende de la MARCA y la PRESENTACIÓN (CONVENCIONAL / PACKED).
//   - customer_contract_prices: precios del contrato por marca + presentación (reemplaza customer_contracts.box_price)
//   - customer_order_lines: marca y presentación de cada línea (el correo de Rusia las trae)
//   - purchase_blocks: presentación del bloque (Comercial sabe si compra fruta packed o convencional)
//   - PALMS CON BANDA era Palms en presentación PACKED: se desactiva como marca.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_contract_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_contract_id')->constrained('customer_contracts')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands');
            $table->string('presentation', 15); // CONVENCIONAL / PACKED
            $table->decimal('box_price', 10, 2);
            $table->timestamps();
            $table->unique(['customer_contract_id', 'brand_id', 'presentation'], 'customer_contract_prices_unico');
        });
        DB::statement("ALTER TABLE customer_contract_prices ADD CONSTRAINT customer_contract_prices_presentation_check CHECK (presentation IN ('CONVENCIONAL','PACKED'))");

        // Contratos que ya existían: su precio único pasa a cada marca del cliente, en las dos presentaciones
        $ahora = now();
        foreach (DB::table('customer_contracts')->whereNotNull('box_price')->get() as $k) {
            $marcas = DB::table('customer_brand')->where('customer_id', $k->customer_id)->pluck('brand_id');
            foreach ($marcas as $marca) {
                foreach (['CONVENCIONAL', 'PACKED'] as $p) {
                    DB::table('customer_contract_prices')->insert([
                        'customer_contract_id' => $k->id, 'brand_id' => $marca, 'presentation' => $p,
                        'box_price' => $k->box_price, 'created_at' => $ahora, 'updated_at' => $ahora,
                    ]);
                }
            }
        }
        Schema::table('customer_contracts', fn (Blueprint $t) => $t->dropColumn('box_price'));

        Schema::table('customer_order_lines', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('customer_id')->constrained('brands');
            $table->string('presentation', 15)->nullable()->after('brand_id');
        });
        DB::statement("ALTER TABLE customer_order_lines ADD CONSTRAINT customer_order_lines_presentation_check CHECK (presentation IN ('CONVENCIONAL','PACKED'))");
        // Líneas que ya existían: primera marca de su cliente, convencional
        DB::statement("UPDATE customer_order_lines l SET presentation = 'CONVENCIONAL',
            brand_id = (SELECT MIN(cb.brand_id) FROM customer_brand cb WHERE cb.customer_id = l.customer_id)");

        Schema::table('purchase_blocks', function (Blueprint $table) {
            $table->string('presentation', 15)->default('CONVENCIONAL')->after('brand_id');
        });
        DB::statement("ALTER TABLE purchase_blocks ADD CONSTRAINT purchase_blocks_presentation_check CHECK (presentation IN ('CONVENCIONAL','PACKED'))");

        DB::table('brands')->where('code', 'PALMS_CON_BANDA')->update(['status' => false, 'updated_at' => $ahora]);
    }

    public function down(): void
    {
        DB::table('brands')->where('code', 'PALMS_CON_BANDA')->update(['status' => true]);
        Schema::table('purchase_blocks', fn (Blueprint $t) => $t->dropColumn('presentation'));
        Schema::table('customer_order_lines', function (Blueprint $t) {
            $t->dropConstrainedForeignId('brand_id');
            $t->dropColumn('presentation');
        });
        Schema::table('customer_contracts', fn (Blueprint $t) => $t->decimal('box_price', 10, 2)->nullable());
        DB::statement('UPDATE customer_contracts k SET box_price = (SELECT MIN(p.box_price) FROM customer_contract_prices p WHERE p.customer_contract_id = k.id)');
        Schema::dropIfExists('customer_contract_prices');
    }
};
