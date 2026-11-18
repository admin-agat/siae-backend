<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 18/11/2026 (Peter): estado PENDIENTE / VERIFICADA de la cuenta bancaria. Contabilidad (o ADMIN) la revisa completa
// y la marca VERIFICADA; si cambia el banco, el número o el titular, vuelve a PENDIENTE. Las cuentas que ya existen
// las cargó la propia Contabilidad, así que quedan VERIFICADAS (por quien las registró).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('third_party_bank_accounts', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
        });
        DB::table('third_party_bank_accounts')->update([
            'verified_at' => DB::raw('COALESCE(updated_at, created_at, NOW())'),
            'verified_by' => DB::raw('updated_by'),
        ]);
    }

    public function down(): void
    {
        Schema::table('third_party_bank_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn('verified_at');
        });
    }
};
