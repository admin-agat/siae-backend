<?php

// TABLEROS (11/11/2026)
// Ubicación: app/Http/Controllers/Api/BoardController.php
//
//   GET /boards/operations?year=&week_from=&week_to=&customer_id=&brand_id=&negotiation_type=
//       Tablero de operaciones (pantalla 'dashboard'): cajas, barcos, contratos, alertas. Sin dinero.
// El cálculo vive en App\Services\Tableros; aquí solo se validan los filtros.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Tableros\TableroOperaciones;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BoardController extends Controller
{
    public function operations(Request $request)
    {
        $hoy = now(config('app.zona_operacion'));
        $datos = $request->validate([
            'year'             => 'nullable|integer|min:2024|max:2100',
            'week_from'        => 'nullable|integer|min:1|max:53',
            'week_to'          => 'nullable|integer|min:1|max:53',
            'customer_id'      => 'nullable|integer|exists:customers,id',
            'brand_id'         => 'nullable|integer|exists:brands,id',
            'negotiation_type' => 'nullable|in:CONTRATO,SPOT',
        ], [
            'year.*'             => 'EL AÑO DEBE ESTAR ENTRE 2024 Y 2100.',
            'week_from.*'        => 'LA SEMANA DESDE DEBE ESTAR ENTRE 1 Y 53.',
            'week_to.*'          => 'LA SEMANA HASTA DEBE ESTAR ENTRE 1 Y 53.',
            'customer_id.*'      => 'EL CLIENTE ELEGIDO NO EXISTE.',
            'brand_id.*'         => 'LA MARCA ELEGIDA NO EXISTE.',
            'negotiation_type.*' => 'EL TIPO DE NEGOCIACIÓN DEBE SER CONTRATO O SPOT.',
        ]);
        // Por defecto: la semana actual y las 7 anteriores
        $anio = (int) ($datos['year'] ?? $hoy->isoWeekYear());
        $hasta = (int) ($datos['week_to'] ?? max((int) ($datos['week_from'] ?? 1), $anio === $hoy->isoWeekYear() ? $hoy->isoWeek() : 52));
        $desde = (int) ($datos['week_from'] ?? max(1, $hasta - 7));
        if ($desde > $hasta) {
            throw ValidationException::withMessages(['week_from' => 'LA SEMANA DESDE NO PUEDE SER MAYOR QUE LA SEMANA HASTA.']);
        }

        return response()->json((new TableroOperaciones($anio, $desde, $hasta, $datos))->datos());
    }
}
