<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

// 09/11/2026 (revisión de seguridad, etapa 2): el número de cuenta bancaria pasa a guardarse cifrado
// (cast 'encrypted' en ThirdPartyBankAccount, con la APP_KEY). La columna pasa a texto porque el valor
// cifrado es largo. Si una fila ya está cifrada (se corrió dos veces), no se vuelve a cifrar.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE third_party_bank_accounts ALTER COLUMN account_number TYPE text');

        DB::table('third_party_bank_accounts')->orderBy('id')->each(function ($fila) {
            if ($this->cifrado($fila->account_number)) {
                return;
            }
            DB::table('third_party_bank_accounts')->where('id', $fila->id)
                ->update(['account_number' => Crypt::encryptString($fila->account_number)]);
        });
    }

    public function down(): void
    {
        DB::table('third_party_bank_accounts')->orderBy('id')->each(function ($fila) {
            if ($this->cifrado($fila->account_number)) {
                DB::table('third_party_bank_accounts')->where('id', $fila->id)
                    ->update(['account_number' => Crypt::decryptString($fila->account_number)]);
            }
        });

        DB::statement('ALTER TABLE third_party_bank_accounts ALTER COLUMN account_number TYPE varchar(30)');
    }

    private function cifrado(?string $valor): bool
    {
        if ($valor === null || ctype_digit($valor)) {
            return false;
        }
        try {
            Crypt::decryptString($valor);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
};
