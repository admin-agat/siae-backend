<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 04/11/2026: la compra de fruta elige la FINCA del catálogo (trazabilidad) en vez de escribirla a mano.
// farm_name y mag_code se quedan como copia de lo que tenía la finca al comprar (el historial no cambia
// si después se edita la finca). Las compras ya hechas se enlazan solas cuando el nombre escrito coincide
// con una finca del productor real (o del vendedor si fue compra directa); las demás quedan sin enlace.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('producer_quota_lines', function (Blueprint $table) {
            $table->foreignId('farm_id')->nullable()->after('producer_id')->constrained('farms')->nullOnDelete();
        });

        // Solo si el nombre coincide con UNA finca de ese productor (sin mayúsculas ni espacios de más)
        DB::statement(<<<'SQL'
            UPDATE producer_quota_lines l
               SET farm_id = f.id
              FROM producer_quotas q, farms f
             WHERE q.id = l.producer_quota_id
               AND l.farm_id IS NULL
               AND l.farm_name IS NOT NULL
               AND f.third_party_id = COALESCE(l.producer_id, q.third_party_id)
               AND UPPER(TRIM(f.name)) = UPPER(TRIM(l.farm_name))
               AND (SELECT COUNT(*) FROM farms f2
                     WHERE f2.third_party_id = f.third_party_id
                       AND UPPER(TRIM(f2.name)) = UPPER(TRIM(f.name))) = 1
        SQL);
    }

    public function down(): void
    {
        Schema::table('producer_quota_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('farm_id');
        });
    }
};
