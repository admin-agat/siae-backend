<?php

// FICHA Y CUENTA BANCARIA DEL VENDEDOR DESDE LIQUIDACIONES (18/11/2026, fase 2b aprobada por Peter)
// Ubicación: app/Http/Controllers/Api/SellerProfileController.php
// Diseño: docs/modulos/compra-fruta-grilla.md §B6 y §B7 (frontend).
//
//   GET  /producer-bank-accounts/{thirdPartyId}   cuenta enmascarada (****1234) — contratos_productores o liquidar
//   POST /producer-bank-accounts/{thirdPartyId}   crea la cuenta si no tiene; cambiarla es de Contabilidad
//   PUT  /producer-profiles/{thirdPartyId}        completa la ficha (cédula si falta, zona, teléfono, correo, dirección) — ficha_vendedores
//
// Solo PRODUCTOR y COMERCIALIZADORA. El número completo de la cuenta nunca sale por estas rutas:
// lo ven Contabilidad y ADMIN en cuentas_bancarias. La bitácora lo guarda enmascarado (Auditable).
// Nombre, tipo y estado del tercero no se cambian aquí: eso es de ADMIN en Terceros.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ThirdParty;
use App\Models\ThirdPartyBankAccount;
use App\Support\CuentaBancaria;
use App\Support\IdentificacionEcuador;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerProfileController extends Controller
{
    private const TIPOS_VENDEDOR = ['PRODUCTOR', 'COMERCIALIZADORA'];

    public function bankAccount($thirdPartyId)
    {
        $tercero = $this->vendedor($thirdPartyId);

        return response()->json(CuentaBancaria::enmascarada($tercero->bankAccount));
    }

    public function storeBankAccount(Request $request, $thirdPartyId)
    {
        $tercero = $this->vendedor($thirdPartyId);
        $data = CuentaBancaria::validar($request);

        $cuenta = DB::transaction(function () use ($tercero, $data, $request) {
            // se bloquea el tercero para que dos personas no registren dos cuentas a la vez
            ThirdParty::whereKey($tercero->id)->lockForUpdate()->first();
            if (ThirdPartyBankAccount::where('third_party_id', $tercero->id)->exists()) {
                abort(response()->json(['message' => 'ESTE VENDEDOR YA TIENE CUENTA BANCARIA: EL CAMBIO LO HACE CONTABILIDAD.'], 422));
            }

            return ThirdPartyBankAccount::create($data + ['third_party_id' => $tercero->id, 'updated_by' => $request->user()->id]);
        });

        return response()->json(CuentaBancaria::enmascarada($cuenta), 201);
    }

    public function saveProfile(Request $request, $thirdPartyId)
    {
        $tercero = $this->vendedor($thirdPartyId);
        $data = $request->validate([
            'identification' => 'nullable|string|max:13',
            'zone' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
        ], [
            'identification.max' => 'LA CÉDULA TIENE 10 DÍGITOS Y EL RUC 13.',
            'email.email' => 'EL CORREO NO TIENE UN FORMATO VÁLIDO.',
            'zone.max' => 'LA ZONA TIENE MÁXIMO 100 LETRAS.',
            'phone.max' => 'EL TELÉFONO TIENE MÁXIMO 30 CARACTERES.',
            'address.max' => 'LA DIRECCIÓN TIENE MÁXIMO 255 LETRAS.',
        ]);
        // Solo se tocan los campos que vienen en la petición: un envío parcial no borra lo que ya estaba
        $limpio = fn ($v) => isset($v) && trim($v) !== '' ? mb_strtoupper(trim($v)) : null;
        $cambios = [];
        foreach (['zone', 'phone', 'address'] as $campo) {
            if ($request->exists($campo)) {
                $cambios[$campo] = $limpio($data[$campo] ?? null);
            }
        }
        if ($request->exists('email')) {
            $cambios['email'] = isset($data['email']) && trim($data['email']) !== '' ? mb_strtolower(trim($data['email'])) : null;
        }

        // La cédula o RUC solo se escribe si el vendedor no la tiene; la que ya existe no se cambia aquí
        $nueva = preg_replace('/\s+/', '', (string) ($data['identification'] ?? ''));
        if ($tercero->identification) {
            if ($nueva !== '' && $nueva !== $tercero->identification) {
                return $this->error('identification', 'LA CÉDULA O RUC YA REGISTRADA NO SE CAMBIA AQUÍ: PÍDELO AL ADMINISTRADOR.');
            }
        } elseif ($nueva !== '') {
            if ($error = IdentificacionEcuador::error($nueva)) {
                return $this->error('identification', 'CÉDULA O RUC NO VÁLIDO: ' . mb_strtoupper($error));
            }
            $otro = ThirdParty::whereRaw('UPPER(TRIM(identification)) = ?', [$nueva])->where('id', '!=', $tercero->id)->first();
            if ($otro) {
                return $this->error('identification', "LA IDENTIFICACIÓN {$nueva} YA ESTÁ REGISTRADA A NOMBRE DE {$otro->name}.");
            }
            $cambios['identification'] = $nueva;
        }

        $tercero->update($cambios);

        return response()->json($tercero->fresh()->only(['id', 'name', 'type', 'identification', 'zone', 'phone', 'email', 'address']));
    }

    private function vendedor($thirdPartyId): ThirdParty
    {
        return ThirdParty::whereIn('type', self::TIPOS_VENDEDOR)->findOrFail($thirdPartyId);
    }

    private function error(string $campo, string $mensaje)
    {
        return response()->json(['message' => $mensaje, 'errors' => [$campo => [$mensaje]]], 422);
    }
}
