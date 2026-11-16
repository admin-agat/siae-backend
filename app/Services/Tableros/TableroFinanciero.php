<?php

// TABLERO FINANCIERO — 16/11/2026
// Ubicación: app/Services/Tableros/TableroFinanciero.php
//
// Suma la "Ganancia por viaje" (VoyageCostController::gananciaDelViaje) de todos los barcos de los
// pedidos del rango de semanas (customer_orders.year/week). No calcula nada distinto: mismos números
// que la pantalla Ganancia por viaje, agrupados por semana, cliente, barco y concepto de costo.
// Si un barco sale "incompleto" (falta precio de venta, precio real o tarifa) se avisa en la lista.
// Solo GERENCIA, FINANZAS y ADMIN (pantalla 'tablero_financiero'). Clientes solo por nombre.

namespace App\Services\Tableros;

use App\Http\Controllers\Api\VoyageCostController;
use App\Models\Shipment;
use App\Models\ShipmentExpense;
use Illuminate\Support\Collection;

class TableroFinanciero
{
    public function __construct(private int $anio, private int $desde, private int $hasta)
    {
    }

    public function datos(): array
    {
        $barcos = Shipment::with(['vessel:id,name', 'order:id,week,year'])
            ->where('status', true)
            ->whereHas('order', fn ($q) => $q->where('status', true)->where('year', $this->anio)->whereBetween('week', [$this->desde, $this->hasta]))
            ->orderBy('departure_date')->orderBy('id')->get();

        $calculo = app(VoyageCostController::class);
        $viajes = $barcos->map(fn ($b) => $calculo->gananciaDelViaje($b))->values();
        $clientes = $viajes->flatMap(fn ($v) => collect($v['customers'])->map(fn ($c) => $c + ['week' => $v['week']]));

        return [
            'rango'     => ['year' => $this->anio, 'week_from' => $this->desde, 'week_to' => $this->hasta],
            'totales'   => $this->totales($clientes) + [
                'barcos'             => $viajes->count(),
                'barcos_incompletos' => $viajes->where('total.incompleto', true)->count(),
            ],
            'semanas'   => $this->porSemana($clientes),
            'por_cliente' => $this->porCliente($clientes),
            'barcos'    => $viajes->map(fn ($v) => [
                'id'             => $v['id'],
                'name'           => $v['name'],
                'week'           => $v['week'],
                'departure_date' => $v['departure_date'],
                'incompleto'     => $v['total']['incompleto'],
                'avisos'         => array_values(array_unique(array_merge($v['avisos'], collect($v['customers'])->flatMap(fn ($c) => $c['avisos'])->all()))),
            ] + $this->totales(collect($v['customers'])))->values(),
            'costos'    => $this->costos($clientes),
            'generado'  => now(config('app.zona_operacion'))->format('Y-m-d H:i'),
        ];
    }

    /** Totales de un grupo de filas cliente-viaje; el costo neto incluye lo recuperado del productor */
    private function totales(Collection $filas): array
    {
        $s = fn ($k) => (float) $filas->sum($k);
        $venta = $s('venta');
        $ganancia = $s('ganancia');
        $cajas = (int) $s('cajas');

        return [
            'cajas'               => $cajas,
            'venta'               => round($venta, 2),
            'fruta'               => round($s('fruta'), 2),
            'materiales'          => round($s('materiales') - $s('materiales_recuperados'), 2),
            'fletes'              => round($s('flete_contenedor') + $s('flete_bajo_cubierta'), 2),
            'transporte'          => round($s('transporte_reconocido') - $s('transporte_recuperado'), 2),
            'gastos'              => round($s('gastos'), 2),
            'costo'               => round($venta - $ganancia, 2),
            'ganancia'            => round($ganancia, 2),
            'margen'              => $venta > 0 ? round($ganancia / $venta * 100, 2) : null,
            'ganancia_por_caja'   => $cajas > 0 ? round($ganancia / $cajas, 4) : null,
        ];
    }

    private function porSemana(Collection $clientes): array
    {
        $res = [];
        for ($w = $this->desde; $w <= $this->hasta; $w++) {
            $res[] = ['week' => $w] + $this->totales($clientes->where('week', $w));
        }
        return $res;
    }

    private function porCliente(Collection $clientes): array
    {
        return $clientes->groupBy('customer_id')
            ->map(fn ($g) => ['customer_id' => (int) $g->first()['customer_id'], 'name' => $g->first()['name']] + $this->totales($g))
            ->sortByDesc('venta')->values()->all();
    }

    /** En qué se va el dinero: fruta, materiales, fletes, transporte y cada categoría de gasto */
    private function costos(Collection $clientes): array
    {
        $t = $this->totales($clientes);
        $res = [
            ['concepto' => 'Fruta', 'monto' => $t['fruta']],
            ['concepto' => 'Materiales (neto)', 'monto' => $t['materiales']],
            ['concepto' => 'Fletes', 'monto' => $t['fletes']],
            ['concepto' => 'Transporte (neto)', 'monto' => $t['transporte']],
        ];
        foreach (ShipmentExpense::CATEGORIAS as $clave => $nombre) {
            $res[] = ['concepto' => $nombre, 'monto' => round((float) $clientes->sum(fn ($c) => $c['gastos_detalle'][$clave] ?? 0), 2)];
        }
        return array_values(array_filter($res, fn ($r) => abs($r['monto']) >= 0.01));
    }
}
