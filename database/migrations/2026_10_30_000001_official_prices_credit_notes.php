<?php

// PRECIO OFICIAL MAG Y NOTA DE CRÉDITO (30/10/2026)
//
// - official_box_prices: precio oficial por caja que fija el MAG (acuerdo ministerial), por tipo
//   de caja y vigencia. La compra de fruta lo propone según el tipo de caja de la marca.
// - brands.box_type_id: tipo de caja de la marca (22XU, 208...).
// - Liquidación: el vendedor factura al precio OFICIAL y devuelve la diferencia con el real en
//   una NOTA DE CRÉDITO:  factura = pagadas × oficial;  NC = factura − pagadas × real.
//   Se registran el N° de factura y de NC cuando llegan (no bloquea el pago).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('official_box_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('box_type_id')->constrained('box_types');
            $table->date('valid_from');
            $table->date('valid_to')->nullable(); // null = vigente hasta nuevo acuerdo
            $table->decimal('price', 10, 2);
            $table->string('agreement_number', 60)->nullable(); // N° de acuerdo ministerial
            $table->string('notes', 255)->nullable();
            $table->boolean('status')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['box_type_id', 'valid_from']);
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->foreignId('box_type_id')->nullable()->constrained('box_types');
        });
        // Hoy todas las marcas son 22XU (único tipo de caja registrado)
        $xu = DB::table('box_types')->where('name', '22XU')->value('id');
        if ($xu) {
            DB::table('brands')->whereNull('box_type_id')->update(['box_type_id' => $xu]);
        }

        Schema::table('settlement_lines', function (Blueprint $table) {
            $table->decimal('official_price', 10, 2)->nullable();
            $table->decimal('invoice_amount', 12, 2)->default(0);     // pagadas × oficial
            $table->decimal('credit_note_amount', 12, 2)->default(0); // factura − pagadas × real
        });

        Schema::table('settlements', function (Blueprint $table) {
            $table->decimal('invoice_amount', 12, 2)->default(0);
            $table->decimal('credit_note_amount', 12, 2)->default(0);
            $table->string('invoice_number', 30)->nullable();
            $table->string('credit_note_number', 30)->nullable();
            $table->date('credit_note_date')->nullable();
            $table->foreignId('documents_by')->nullable()->constrained('users');
        });

        // Liquidaciones ya hechas: toman el precio oficial de su compra
        DB::statement("
            UPDATE settlement_lines sl
               SET official_price = pql.official_price,
                   invoice_amount = ROUND(sl.boxes_paid * pql.official_price, 2),
                   credit_note_amount = ROUND(sl.boxes_paid * pql.official_price, 2) - sl.amount
              FROM producer_quota_lines pql
             WHERE pql.id = sl.producer_quota_line_id AND pql.official_price IS NOT NULL
        ");
        DB::statement("
            UPDATE settlements s
               SET invoice_amount = t.factura, credit_note_amount = t.nc
              FROM (SELECT settlement_id, SUM(invoice_amount) factura, SUM(credit_note_amount) nc
                      FROM settlement_lines GROUP BY settlement_id) t
             WHERE t.settlement_id = s.id
        ");
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('documents_by');
            $table->dropColumn(['invoice_amount', 'credit_note_amount', 'invoice_number', 'credit_note_number', 'credit_note_date']);
        });
        Schema::table('settlement_lines', function (Blueprint $table) {
            $table->dropColumn(['official_price', 'invoice_amount', 'credit_note_amount']);
        });
        Schema::table('brands', function (Blueprint $table) {
            $table->dropConstrainedForeignId('box_type_id');
        });
        Schema::dropIfExists('official_box_prices');
    }
};
