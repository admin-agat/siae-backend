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
        $concepto = trim($orden->code . ' ' . ($orden->reference ?? ''));

        return [
            'PA',
            self::limpiar($c->holder_id, 20),
            'USD',
            str_pad((string) (int) round(self::valor($orden) * 100), 13, '0', STR_PAD_LEFT),
            'CTA',
            $c->account_type === 'CORRIENTE' ? 'CTE' : 'AHO',
            self::limpiar($c->account_number, 30),
            self::limpiar($concepto, 40),
            $c->holder_id_type,
            self::limpiar($c->holder_id, 20),
            self::limpiar($c->holder_name, 40),
            self::limpiar($c->bank->code, 10),
        ];
    }

    /** El archivo completo de un lote. */
    public static function archivo(iterable $ordenes): string
    {
        $lineas = [];
        foreach ($ordenes as $orden) {
            $lineas[] = implode("\t", self::columnas($orden));
        }

        return implode("\r\n", $lineas) . "\r\n";
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
