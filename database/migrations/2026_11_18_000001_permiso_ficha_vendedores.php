<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 18/11/2026 (fase 2b, aprobada por Peter): nuevo permiso "Ficha de vendedores" (ficha_vendedores).
// Liquidaciones completa la cédula si falta, la zona, el teléfono, el correo y la dirección de productores
// y comercializadoras sin entrar a Terceros. Se les da a los perfiles que ya tienen Retención de productores
// (contratos_productores). No crea tablas: la cuenta bancaria usa third_party_bank_accounts, que ya existe.
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('profiles')->get(['id', 'screens']) as $p) {
            $pantallas = json_decode($p->screens ?? '[]', true) ?: [];
            if (in_array('ficha_vendedores', $pantallas) || !in_array('contratos_productores', $pantallas)) {
                continue;
            }
            $pantallas[] = 'ficha_vendedores';
            DB::table('profiles')->where('id', $p->id)->update(['screens' => json_encode($pantallas), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('profiles')->get(['id', 'screens']) as $p) {
            $pantallas = json_decode($p->screens ?? '[]', true) ?: [];
            if (in_array('ficha_vendedores', $pantallas)) {
                DB::table('profiles')->where('id', $p->id)->update(['screens' => json_encode(array_values(array_diff($pantallas, ['ficha_vendedores'])))]);
            }
        }
    }
};
