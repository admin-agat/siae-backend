<?php

// SALDO DE VACACIONES — 16/11/2026 (vacaciones fase 1, docs/modulos/vacaciones-permisos.md §7)
// Ubicación: app/Services/SaldoVacaciones.php
//
//   Días por año de servicio n = 15 + min(15, max(0, n − 5 + 1))   → año 1–4: 15 · año 5: 16 · año 19+: 30
//     (P1 de Peter: el adicional empieza en el 5.º año; se cambia en config/vacaciones.php)
//   Acreditar: por cada aniversario (ingreso + n años) que ya pasó (hora Ecuador), posterior a la fecha
//     de corte del saldo inicial y no posterior a la salida, se agrega GANADO al kárdex si no existe
//     (índice único: acreditar dos veces no duplica). Lo hace el comando diario y cada consulta de saldo.
//   Saldo = suma del kárdex. Proporcional en curso (informativo) = días del año que corre × días desde
//     el último aniversario ÷ 365.
//   Aviso de acumulación (P8): lo ganado hace más de 3 años que sigue sin gozar (se descuenta primero
//     lo más viejo). Es un cálculo al vuelo, no se guarda.

namespace App\Services;

use App\Models\Employee;
use App\Models\VacationMovement;
use Carbon\Carbon;
use Illuminate\Database\QueryException;

class SaldoVacaciones
{
    public function diasPorAnio(int $n): int
    {
        $c = config('vacaciones');
        $adicional = min($c['max_adicionales'], max(0, $n - $c['anio_desde_adicional'] + 1));

        return $c['dias_base'] + $adicional;
    }

    private function hoy(): Carbon
    {
        return now(config('app.zona_operacion'))->startOfDay();
    }

    /** Agrega al kárdex los GANADO que faltan. Devuelve cuántos agregó. */
    public function acreditarPendientes(Employee $e): int
    {
        if (!$e->hire_date) {
            return 0;
        }
        $hoy = $this->hoy();
        $ya = $e->movements()->where('type', 'GANADO')->pluck('service_year')->all();
        $nuevos = 0;
        for ($n = 1; $n <= 80; $n++) {
            $aniversario = Carbon::parse($e->hire_date->format('Y-m-d'), config('app.zona_operacion'))->addYearsNoOverflow($n);
            // Se compara por día (Y-m-d): las fechas del modelo vienen en UTC y el aniversario en hora Ecuador
            $dia = $aniversario->format('Y-m-d');
            if ($aniversario->gt($hoy) || ($e->termination_date && $dia > $e->termination_date->format('Y-m-d'))) {
                break;
            }
            if (in_array($n, $ya, true) || ($e->vacation_cutoff_date && $dia <= $e->vacation_cutoff_date->format('Y-m-d'))) {
                continue;
            }
            try {
                VacationMovement::create([
                    'employee_id'   => $e->id,
                    'movement_date' => $aniversario->format('Y-m-d'),
                    'type'          => 'GANADO',
                    'days'          => $this->diasPorAnio($n),
                    'service_year'  => $n,
                    'reason'        => "AÑO {$n} DE SERVICIO",
                    'created_by'    => null,
                ]);
                $nuevos++;
            } catch (QueryException $ex) {
                // otro proceso lo acreditó al mismo tiempo (índice único): no es error
                if (!str_contains($ex->getMessage(), 'evm_ganado_unique')) {
                    throw $ex;
                }
            }
        }

        return $nuevos;
    }

    /** Resumen del saldo (acredita antes lo pendiente) */
    public function resumen(Employee $e): array
    {
        $this->acreditarPendientes($e);
        $movs = $e->movements()->orderBy('movement_date')->orderBy('id')->get(['movement_date', 'type', 'days']);
        $hoy = $this->hoy();

        $anios = $e->hire_date ? (int) $e->hire_date->diffInYears($hoy) : 0;
        $ultimo = $e->hire_date ? Carbon::parse($e->hire_date->format('Y-m-d'), config('app.zona_operacion'))->addYearsNoOverflow($anios) : null;
        $proximo = $ultimo?->copy()->addYear();
        $activo = $e->status && !$e->termination_date;
        $proporcional = ($activo && $ultimo)
            ? round($this->diasPorAnio($anios + 1) * $ultimo->diffInDays($hoy) / 365, 2) : 0;

        return [
            'saldo'               => round((float) $movs->sum('days'), 3),
            'anios_servicio'      => $anios,
            'proximo_aniversario' => $activo ? $proximo?->format('Y-m-d') : null,
            'dias_proximo'        => $activo ? $this->diasPorAnio($anios + 1) : null,
            'proporcional'        => $proporcional,
            'acumulado_viejo'     => $this->acumuladoViejo($movs, $hoy),
        ];
    }

    /**
     * Días ganados hace más de N años que siguen sin gozar: se consume primero lo más viejo
     * (SALDO_INICIAL y GANADO son los "lotes"; lo negativo los va gastando en orden).
     */
    private function acumuladoViejo($movs, Carbon $hoy): float
    {
        $limite = $hoy->copy()->subYears(config('vacaciones.anios_acumulacion_aviso'));
        $lotes = [];
        $gastado = 0.0;
        foreach ($movs as $m) {
            $d = (float) $m->days;
            if ($d > 0 && in_array($m->type, ['SALDO_INICIAL', 'GANADO'], true)) {
                $lotes[] = ['fecha' => $m->movement_date, 'dias' => $d];
            } elseif ($d < 0) {
                $gastado += -$d;
            } else {
                $gastado -= $d; // REVERSO / AJUSTE positivo devuelve días
            }
        }
        $viejo = 0.0;
        foreach ($lotes as $l) {
            $usa = min($l['dias'], max(0, $gastado));
            $gastado -= $usa;
            if ($l['fecha']->lt($limite)) {
                $viejo += $l['dias'] - $usa;
            }
        }

        return round($viejo, 3);
    }
}
