<?php

// MATERIALES DEL PEDIDO (preliminar) — 16/11/2026
// Ubicación: app/Http/Controllers/Api/OrderMaterialsController.php
//
//   POST /customer-orders/materials   { customer_order_id?, lines: [{brand_id, presentation, boxes}] }
//        Pedido semanal (pantalla pedido_semanal): calcula con las líneas que se están editando, aunque
//        no estén guardadas. Costo en $ solo para GERENCIA, FINANZAS y ADMIN.
//   GET  /material-dispatch/order-materials?year=&week=
//        Despacho de materiales (pantalla despacho): el pedido activo de esa semana, SIN dólares.
//        El bodeguero ve solo el stock de su bodega (el faltante se calcula con todas).
// El cálculo vive en App\Services\MaterialesPedido.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerOrder;
use App\Services\MaterialesPedido;
use Illuminate\Http\Request;

class OrderMaterialsController extends Controller
{
    public function estimate(Request $request, MaterialesPedido $servicio)
    {
        $datos = $request->validate([
            'customer_order_id'    => 'nullable|integer|exists:customer_orders,id',
            'lines'                => 'present|array|max:500',
            'lines.*.brand_id'     => 'nullable|integer',
            'lines.*.presentation' => 'nullable|in:CONVENCIONAL,PACKED',
            'lines.*.boxes'        => 'nullable|integer|min:0|max:10000000',
        ], [
            'customer_order_id.*'    => 'EL PEDIDO NO EXISTE.',
            'lines.*'                => 'FALTAN LAS LÍNEAS DEL PEDIDO.',
            'lines.*.brand_id.*'     => 'LA MARCA DE UNA LÍNEA NO ES VÁLIDA.',
            'lines.*.presentation.*' => 'LA PRESENTACIÓN DEBE SER NORMAL O BANDA.',
            'lines.*.boxes.*'        => 'LAS CAJAS DE UNA LÍNEA DEBEN SER UN NÚMERO ENTERO POSITIVO.',
        ]);

        return response()->json($servicio->calcular($datos['lines'], $datos['customer_order_id'] ?? null, $request->user()));
    }

    public function forWarehouse(Request $request, MaterialesPedido $servicio)
    {
        $datos = $request->validate([
            'year' => 'required|integer|min:2024|max:2100',
            'week' => 'required|integer|min:1|max:53',
        ], [
            'year.*' => 'EL AÑO DEBE ESTAR ENTRE 2024 Y 2100.',
            'week.*' => 'LA SEMANA DEBE ESTAR ENTRE 1 Y 53.',
        ]);
        $pedido = CustomerOrder::with(['lines' => fn ($q) => $q->where('status', true)])
            ->where('status', true)->where('year', $datos['year'])->where('week', $datos['week'])->first();
        if (!$pedido) {
            return response()->json(['pedido' => null]);
        }
        $lineas = $pedido->lines->map(fn ($l) => ['brand_id' => $l->brand_id, 'presentation' => $l->presentation, 'boxes' => $l->boxes])->all();

        return response()->json(['pedido' => ['id' => $pedido->id, 'year' => $pedido->year, 'week' => $pedido->week]]
            + $servicio->calcular($lineas, $pedido->id, $request->user(), false));
    }
}
