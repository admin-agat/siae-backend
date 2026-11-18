<?php

namespace App\Support;

use App\Models\ThirdPartyBankAccount;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// 18/11/2026 (fase 2b, aprobada por Peter): reglas de la cuenta bancaria de un tercero en un solo lugar.
// Las usan Contabilidad (cuentas_bancarias, ve y corrige la cuenta completa) y Liquidaciones (solo la crea
// y después la ve enmascarada ****1234). Antes vivían dentro de AccountingController::saveBankAccount.
class CuentaBancaria
{
    /** Valida y normaliza los datos de la cuenta; lanza 422 con mensajes en MAYÚSCULAS. */
    public static function validar(Request $request): array
    {
        $data = $request->validate([
            'bank_id' => ['required', Rule::exists('banks', 'id')->where('status', true)],
            'account_type' => 'required|in:AHORROS,CORRIENTE',
            'account_number' => ['required', 'string', 'max:30', 'regex:/^[0-9]+$/'],
            'holder_id_type' => 'required|in:C,R,P',
            'holder_id' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z]+$/'],
            'holder_name' => 'required|string|max:120',
            'email' => 'nullable|email|max:120',
        ], [
            'bank_id.required' => 'ELIGE EL BANCO.',
            'bank_id.exists' => 'EL BANCO NO EXISTE O ESTÁ DESACTIVADO.',
            'account_type.required' => 'ELIGE SI LA CUENTA ES DE AHORROS O CORRIENTE.',
            'account_number.required' => 'ESCRIBE EL NÚMERO DE CUENTA.',
            'account_number.regex' => 'EL NÚMERO DE CUENTA LLEVA SOLO DÍGITOS.',
            'holder_id_type.required' => 'ELIGE EL TIPO DE IDENTIFICACIÓN DEL TITULAR.',
            'holder_id.required' => 'ESCRIBE LA IDENTIFICACIÓN DEL TITULAR.',
            'holder_id.regex' => 'LA IDENTIFICACIÓN LLEVA SOLO NÚMEROS (O LETRAS SI ES PASAPORTE).',
            'holder_name.required' => 'ESCRIBE EL NOMBRE DEL TITULAR.',
            'email.email' => 'EL CORREO NO TIENE UN FORMATO VÁLIDO.',
        ]);
        if ($data['holder_id_type'] === 'C' && strlen($data['holder_id']) !== 10) {
            throw ValidationException::withMessages(['holder_id' => 'LA CÉDULA TIENE 10 DÍGITOS.']);
        }
        if ($data['holder_id_type'] === 'R' && strlen($data['holder_id']) !== 13) {
            throw ValidationException::withMessages(['holder_id' => 'EL RUC TIENE 13 DÍGITOS.']);
        }
        $data['holder_id'] = mb_strtoupper($data['holder_id']);
        $data['holder_name'] = mb_strtoupper(trim($data['holder_name']));
        $data['email'] = isset($data['email']) && trim($data['email']) !== '' ? mb_strtolower(trim($data['email'])) : null;

        return $data;
    }

    /** Lo que ve Liquidaciones: nunca el número ni la identificación completos. */
    public static function enmascarada(?ThirdPartyBankAccount $cuenta): array
    {
        if (!$cuenta) {
            return ['tiene_cuenta' => false];
        }
        $cuenta->loadMissing(['bank:id,name', 'updater:id,name']);

        return [
            'tiene_cuenta' => true,
            'banco' => $cuenta->bank?->name,
            'account_type' => $cuenta->account_type,
            'numero' => self::ultimos($cuenta->account_number, '****'),
            'holder_id_type' => $cuenta->holder_id_type,
            'holder_id' => self::ultimos($cuenta->holder_id, '######'),
            'holder_name' => $cuenta->holder_name,
            'registrada_por' => $cuenta->updater?->name,
            'registrada_el' => $cuenta->created_at?->toDateString(),
        ];
    }

    /** "****1234": solo los 4 últimos caracteres. */
    public static function ultimos(?string $valor, string $relleno): string
    {
        $valor = (string) $valor;

        return $valor === '' ? '' : $relleno . substr($valor, -4);
    }
}
