<?php

use App\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 09/11/2026 (revisión de seguridad): la bitácora guardaba el número de cuenta bancaria completo.
// Desde ahora se registra como ****1234 (Auditable); aquí se enmascaran los registros que ya existían.
// No tiene vuelta atrás: el número completo sigue en third_party_bank_accounts.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('audit_logs')->where('table_name', 'third_party_bank_accounts')->whereNotNull('changes')
            ->orderBy('id')->each(function ($fila) {
                $cambios = json_decode($fila->changes, true);
                if (!is_array($cambios) || !array_key_exists('account_number', $cambios)) {
                    return;
                }
                $v = $cambios['account_number'];
                // CREAR/BORRAR: valor suelto; EDITAR: [antes, después]
                $cambios['account_number'] = is_array($v) ? array_map([AuditLog::class, 'enmascarar'], $v) : AuditLog::enmascarar($v);
                DB::table('audit_logs')->where('id', $fila->id)->update(['changes' => json_encode($cambios, JSON_UNESCAPED_UNICODE)]);
            });
    }

    public function down(): void
    {
        // Los números completos no se restauran desde la bitácora
    }
};
