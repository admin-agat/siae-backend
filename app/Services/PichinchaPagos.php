<?php

namespace App\Services;

use App\Models\PurchaseOrder;

// Archivo de pagos masivos (cash management) de Banco Pichincha — 05/11/2026.
//
// FORMATO PROVISIONAL: es el layout estándar de pagos del Pichincha (una línea por pago, campos
// separados por TAB, fin de línea CRLF). Hay que CONFIRMARLO con un archivo real que Contabilidad
// ya haya subido al banco antes de usarlo con dinero de verdad. Si el banco pide otro orden o
// largo, se cambia SOLO aquí (columnas()).
//
// Columnas (en orden):
//   1 PA               código de orientación: pago
//   2 contrapartida    identificador del beneficiario (su cédula/RUC)
//   3 USD              moneda
//   4 valor            en centavos, 13 dígitos con ceros a la izquierda (125,50 → 0000000012550)
//   5 CTA              forma de pago: crédito a cuenta
//   6 AHO | CTE        tipo de cuenta
//   7 número de cuenta
//   8 referencia       OC + concepto (máx. 40)
//   9 C | R | P        tipo de identificación del titular
//  10 identificación del titular
//  11 nombre del titular (máx. 40)
//  12 código del banco del beneficiario (tabla Bancos)
class PichinchaPagos
{
    /** Valor a pagar de una OC: su total (subtotal − descuento + IVA − retención IR). */
    public static function valor(PurchaseOrder $orden): float
    {
        return round((float) $orden->total, 2);
    }

    /**
     * Problemas que impiden pagar una OC por el banco (vacío = se puede).
     * La OC debe venir con thirdParty.bankAccount.bank cargado.
     */
    public static function problemas(PurchaseOrder $orden): array
    {
        $p = [];
        if ($orden->approval_status !== 'APROBADA') {
            $p[] = 'No está aprobada.';
        }
        if ($orden->status === 'CANCELADA') {
            $p[] = 'Está cancelada.';
        }
        if ($orden->payment_status) {
            $p[] = $orden->payment_status === 'PAGADA' ? 'Ya está pagada.' : 'Ya está en otro lote del banco.';
        }
        if (self::valor($orden) <= 0) {
            $p[] = 'El valor a pagar es cero.';
        }
        $cuenta = $orden->thirdParty?->bankAccount;
        if (!$cuenta) {
            $p[] = 'El proveedor no tiene cuenta bancaria registrada.';
        } elseif (!$cuenta->bank?->code) {
            $p[] = "Falta el código del banco {$cuenta->bank?->name} (Cuentas bancarias → Bancos).";
        }

        return $p;
    }

    /** Las columnas de una línea del archivo. */
    public static function columnas(PurchaseOrder $orden): array
    {
        $c = $orden->thirdParty->bankAccount;

        return self::columnasPagable([
            'valor'          => self::valor($orden),
            'referencia'     => trim($orden->code . ' ' . ($orden->reference ?? '')),
            'account_type'   => $c->account_type,
            'account_number' => $c->account_number,
            'holder_id_type' => $c->holder_id_type,
            'holder_id'      => $c->holder_id,
            'holder_name'    => $c->holder_name,
            'bank_code'      => $c->bank->code,
        ]);
    }

    /**
     * 19/11/2026 (fase 1b-1): una línea de cualquier "pagable" (OC, anticipo 80 %…) con la cuenta ya resuelta
     * (para los anticipos, la copia congelada en bank_payment_batch_items). El formato del archivo no cambia.
     */
    public static function columnasPagable(array $p): array
    {
        return [
            'PA',
            self::limpiar($p['holder_id'], 20),
            'USD',
            str_pad((string) (int) round($p['valor'] * 100), 13, '0', STR_PAD_LEFT),
            'CTA',
            $p['account_type'] === 'CORRIENTE' ? 'CTE' : 'AHO',
            self::limpiar($p['account_number'], 30),
            self::limpiar($p['referencia'], 40),
            $p['holder_id_type'],
            self::limpiar($p['holder_id'], 20),
            self::limpiar($p['holder_name'], 40),
            self::limpiar($p['bank_code'], 10),
        ];
    }

    /** El archivo completo de un lote (OC o pagables ya armados como arreglo). */
    public static function archivo(iterable $ordenes): string
    {
        $lineas = [];
        foreach ($ordenes as $orden) {
            $lineas[] = implode("\t", is_array($orden) ? self::columnasPagable($orden) : self::columnas($orden));
        }

        return implode("\r\n", $lineas) . "\r\n";
    }

    /**
     * 19/11/2026 (fase 1b-1): problemas que impiden pagar un anticipo por el banco (vacío = se puede).
     * Además de lo de las OC, la cuenta del vendedor debe estar VERIFICADA por Contabilidad.
     */
    public static function problemasAnticipo(\App\Models\ProducerAdvance $a, ?\App\Models\ThirdPartyBankAccount $cuenta): array
    {
        $p = [];
        if (!$a->status || $a->approval_status !== 'APROBADA') {
            $p[] = 'NO ESTÁ APROBADA.';
        }
        if (in_array($a->payment_status, ['EN_BANCO', 'PAGADA'], true)) {
            $p[] = $a->payment_status === 'PAGADA' ? 'YA ESTÁ PAGADA.' : 'YA ESTÁ EN OTRO LOTE DEL BANCO.';
        }
        if ((float) $a->amount <= 0) {
            $p[] = 'EL VALOR A PAGAR ES CERO.';
        }
        if (!$cuenta) {
            $p[] = 'NO SE PUEDE PAGAR: SIN CUENTA BANCARIA.';
        } else {
            if (!$cuenta->verificada()) {
                $p[] = 'LA CUENTA DEL VENDEDOR ESTÁ PENDIENTE: VERIFÍCALA EN CUENTAS BANCARIAS.';
            }
            if (!$cuenta->bank?->code) {
                $p[] = "FALTA EL CÓDIGO DEL BANCO {$cuenta->bank?->name} (CUENTAS BANCARIAS → BANCOS).";
            }
        }

        return $p;
    }

    // Mayúsculas, sin tildes ni Ñ, sin tabs ni saltos (el banco rechaza caracteres especiales), cortado al largo.
    private static function limpiar(?string $texto, int $largo): string
    {
        $t = mb_strtoupper(trim((string) $texto));
        $t = strtr($t, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
        $t = preg_replace('/[^A-Z0-9 .,\-\/]/', ' ', $t);
        $t = preg_replace('/\s+/', ' ', $t);

        return mb_substr(trim($t), 0, $largo);
    }
}
