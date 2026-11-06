<?php

namespace App\Services;

use App\Models\RetentionBracket;

// RETENCIÓN IR ÚNICO BANANO (Art. 27 LRTI) — igual que la hoja CALCULADORA BANANERA del Excel (06/11/2026)
//   NORMAL     → tasa efectiva por tramos según las cajas DE ESA LIQUIDACIÓN, redondeada a 2 decimales
//                (ej. 4.761 cajas: 1.000 × 1 % + 3.761 × 1,25 % = 57,01 → 1,1975 % → 1,20 %)
//   BPA        → tasa fija de los parámetros de la semana (hoy 1 %)
//   NO_RETENER → 0 (asociaciones y cooperativas)
//   Retención = total fruta de la liquidación × tasa, redondeada al centavo (lo mismo que va a Contífico).
class RetencionBanano
{
    /** @var array<int, array{0:int,1:?int,2:float}>|null */
    private ?array $tramos;

    public function __construct(?array $tramos = null)
    {
        $this->tramos = $tramos;
    }

    public function tramos(): array
    {
        return $this->tramos ??= RetentionBracket::orderBy('from_boxes')->get()
            ->map(fn ($t) => [(int) $t->from_boxes, $t->to_boxes === null ? null : (int) $t->to_boxes, (float) $t->rate])
            ->all();
    }

    public function tasa(int $cajas, string $regimen, float $tasaBpa): float
    {
        if ($cajas <= 0 || $regimen === 'NO_RETENER') {
            return 0.0;
        }
        if ($regimen === 'BPA') {
            return $tasaBpa;
        }
        $retenido = 0.0;
        foreach ($this->tramos() as [$desde, $hasta, $tarifa]) {
            if ($cajas > $desde) {
                $retenido += (min($cajas, $hasta ?? PHP_INT_MAX) - $desde) * $tarifa;
            }
        }

        return round($retenido / $cajas * 100, 2) / 100;
    }

    public function retencion(float $totalFruta, float $tasa): float
    {
        return round($totalFruta * $tasa, 2);
    }
}
