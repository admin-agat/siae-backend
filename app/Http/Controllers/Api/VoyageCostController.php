<?php

// GASTOS Y GANANCIA POR VIAJE (Finanzas) — 02/11/2026
// Ubicación: app/Http/Controllers/Api/VoyageCostController.php
//
// Cada nave (viaje) cobra distinto: Finanzas registra por viaje la tarifa por contenedor, el flete
// bajo cubierta y los demás gastos con su factura. El reporte calcula, por viaje y por cliente:
//   venta − fruta − materiales despachados + materiales cobrados al productor
//   − flete por contenedor (solo ventas CFR/CIF: en FOB el flete lo paga el cliente)
//   − flete bajo cubierta − otros gastos + transporte descontado al productor = GANANCIA
// Si falta un dato (precio de venta o de compra, tarifa del flete) el cliente sale "incompleto".
//
//   GET /voyage-costs/{shipmentId}        tarifas, gastos y contenedores del viaje
//   PUT /voyage-costs/{shipmentId}        guarda tarifas y gastos (reemplaza la lista)
//   GET /voyage-profit?customer_order_id= ganancia de los viajes de esa semana

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerOrder;
use App\Models\Shipment;
use App\Models\ShipmentCost;
use App\Models\ShipmentExpense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoyageCostController extends Controller
{
    private const INCOTERMS_CON_FLETE = ['CFR', 'CIF'];

    // GET /voyage-costs?customer_order_id=  → barcos de la semana con lo registrado de cada uno
    public function index(Request $request)
    {
        $request->validate(['customer_order_id' => 'required|exists:customer_orders,id']);
        $barcos = Shipment::with(['vessel:id,name', 'order:id,week,year'])->where('customer_order_id', $request->customer_order_id)
            ->where('status', true)->orderBy('departure_date')->orderBy('id')->get();
        $costos = ShipmentCost::whereIn('shipment_id', $barcos->pluck('id'))->get()->keyBy('shipment_id');
        $gastos = ShipmentExpense::whereIn('shipment_id', $barcos->pluck('id'))->groupBy('shipment_id')
            ->selectRaw('shipment_id, COUNT(*) as n, SUM(amount) as total')->get()->keyBy('shipment_id');

        return response()->json($barcos->map(function ($b) use ($costos, $gastos) {
            $carga = collect($this->cargaPorCliente($b));
            return $this->datosBarco($b) + [
                'container_rate'         => $costos->get($b->id)?->container_rate,
                'under_deck_amount'      => $costos->get($b->id)?->under_deck_amount,
                'expenses_count'         => (int) ($gastos->get($b->id)?->n ?? 0),
                'expenses_total'         => round((float) ($gastos->get($b->id)?->total ?? 0), 2),
                'cajas'                  => $carga->sum('cajas'),
                'cajas_bajo_cubierta'    => $carga->sum('cajas_bajo_cubierta'),
                'contenedores'           => $carga->sum('contenedores'),
                'contenedores_con_flete' => $carga->sum('contenedores_con_flete'),
            ];
        })->values());
    }

    public function show($shipmentId)
    {
        $barco = Shipment::with(['vessel:id,name', 'order:id,week,year'])->where('status', true)->findOrFail($shipmentId);
        $costo = ShipmentCost::where('shipment_id', $barco->id)->first();
        $gastos = ShipmentExpense::with('supplier:id,name')->where('shipment_id', $barco->id)->orderBy('id')->get();

        return response()->json([
            'shipment'   => $this->datosBarco($barco),
            'costs'      => [
                'container_rate'    => $costo?->container_rate,
                'under_deck_amount' => $costo?->under_deck_amount,
                'notes'             => $costo?->notes,
            ],
            'expenses'   => $gastos,
            'customers'  => $this->cargaPorCliente($barco),
            'categories' => ShipmentExpense::CATEGORIAS,
        ]);
    }

    public function update(Request $request, $shipmentId)
    {
        $barco = Shipment::where('status', true)->findOrFail($shipmentId);
        $data = $request->validate([
            'container_rate'            => 'nullable|numeric|min:0',
            'under_deck_amount'         => 'nullable|numeric|min:0',
            'notes'                     => 'nullable|string|max:255',
            'expenses'                  => 'present|array',
            'expenses.*.id'             => 'nullable|integer',
            'expenses.*.customer_id'    => 'nullable|exists:customers,id',
            'expenses.*.category'       => 'required|in:' . implode(',', array_keys(ShipmentExpense::CATEGORIAS)),
            'expenses.*.description'    => 'nullable|string|max:150',
            'expenses.*.supplier_id'    => 'nullable|exists:third_parties,id',
            'expenses.*.invoice_number' => 'nullable|string|max:40',
            'expenses.*.invoice_date'   => 'nullable|date',
            'expenses.*.amount'         => 'required|numeric|min:0',
        ], [
            'expenses.*.category.required' => 'CADA GASTO DEBE TENER CATEGORÍA.',
            'expenses.*.amount.required'   => 'CADA GASTO DEBE TENER MONTO.',
        ]);

        // validate() arma la lista campo por campo: un gasto con cliente puede quedar antes que el
        // anterior. Se reordena para guardar (y numerar los errores) en el orden en que se escribieron.
        ksort($data['expenses']);
        $actuales = ShipmentExpense::where('shipment_id', $barco->id)->get()->keyBy('id');
        $errores = [];
        foreach ($data['expenses'] as $i => $g) {
            if (!empty($g['id']) && !$actuales->has($g['id'])) {
                $errores["expenses.$i"] = 'GASTO ' . ($i + 1) . ': NO ES DE ESTE VIAJE.';
            }
            if (($g['category'] === 'OTRO') && trim($g['description'] ?? '') === '') {
                $errores["expenses.$i.description"] = 'GASTO ' . ($i + 1) . ': ESCRIBE QUÉ ES (CATEGORÍA OTRO).';
            }
        }
        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        $mayus = fn ($v) => isset($v) && trim($v) !== '' ? mb_strtoupper(trim($v)) : null;
        DB::transaction(function () use ($barco, $data, $actuales, $mayus, $request) {
            ShipmentCost::updateOrCreate(['shipment_id' => $barco->id], [
                'container_rate'    => $data['container_rate'] ?? null,
                'under_deck_amount' => $data['under_deck_amount'] ?? null,
                'notes'             => $mayus($data['notes'] ?? null),
                'updated_by'        => $request->user()?->id,
            ]);
            $ids = [];
            foreach ($data['expenses'] as $g) {
                $valores = [
                    'shipment_id'    => $barco->id,
                    'customer_id'    => $g['customer_id'] ?? null,
                    'category'       => $g['category'],
                    'description'    => $mayus($g['description'] ?? null),
                    'supplier_id'    => $g['supplier_id'] ?? null,
                    'invoice_number' => $mayus($g['invoice_number'] ?? null),
                    'invoice_date'   => $g['invoice_date'] ?? null,
                    'amount'         => $g['amount'],
                ];
                $actual = !empty($g['id']) ? $actuales->get($g['id']) : null;
                if ($actual) {
                    $actual->update($valores);
                    $ids[] = $actual->id;
                } else {
                    $ids[] = ShipmentExpense::create($valores + ['created_by' => $request->user()?->id])->id;
                }
            }
            $actuales->except($ids)->each->delete(); // uno por uno: queda en la bitácora
        });

        return $this->show($barco->id);
    }

    // GET /voyage-profit?customer_order_id=  → viajes de la semana con su ganancia por cliente
    public function profit(Request $request)
    {
        $request->validate(['customer_order_id' => 'required|exists:customer_orders,id']);
        $pedido = CustomerOrder::findOrFail($request->customer_order_id);
        $barcos = Shipment::with('vessel:id,name')->where('customer_order_id', $pedido->id)->where('status', true)
            ->orderBy('departure_date')->orderBy('id')->get();

        $viajes = $barcos->map(fn ($b) => $this->gananciaDelViaje($b))->values();
        $campos = ['cajas', 'venta', 'fruta', 'materiales', 'materiales_recuperados', 'transporte_recuperado', 'flete_contenedor', 'flete_bajo_cubierta', 'gastos', 'ganancia'];
        $total = collect($campos)->mapWithKeys(fn ($c) => [$c => round($viajes->sum(fn ($v) => $v['total'][$c]), 2)])->all();
        $total['margen'] = $total['venta'] > 0 ? round($total['ganancia'] / $total['venta'] * 100, 2) : null;
        $total['incompleto'] = $viajes->contains(fn ($v) => $v['total']['incompleto']);

        return response()->json([
            'order'      => $pedido->only(['id', 'week', 'year']),
            'shipments'  => $viajes,
            'total'      => $total,
            'categories' => ShipmentExpense::CATEGORIAS,
        ]);
    }

    // ---------------------------------------------------------------- internos

    private function datosBarco(Shipment $b): array
    {
        $nombre = $b->vessel?->name ?: $b->vessel_name;
        return [
            'id'             => $b->id,
            'name'           => $nombre . ($b->voyage && !str_contains((string) $nombre, (string) $b->voyage) ? " {$b->voyage}" : ''),
            'voyage'         => $b->voyage,
            'departure_date' => $b->departure_date?->format('Y-m-d'),
            'week'           => $b->order?->week,
            'year'           => $b->order?->year,
        ];
    }

    /**
     * Carga de cada cliente en el viaje: cajas y contenedores (del Cierre si ya se cerró; si no, de la
     * Distribución en barcos), qué parte de sus contenedores es CFR/CIF y sus cajas bajo cubierta.
     */
    private function cargaPorCliente(Shipment $b): array
    {
        $lineas = DB::table('shipment_lines as sl')
            ->join('customer_order_lines as ol', 'ol.id', '=', 'sl.customer_order_line_id')
            ->where('sl.shipment_id', $b->id)
            ->get(['sl.customer_id', 'sl.box_quantity', 'sl.container_quantity', 'ol.incoterm', 'ol.cargo_type', 'ol.box_price']);
        $bookings = DB::table('shipment_bookings')->where('shipment_id', $b->id)
            ->get(['customer_id', 'cargo_mode', 'containers_qty', 'shipped_boxes']);
        $nombres = DB::table('customers as c')->join('third_parties as t', 't.id', '=', 'c.third_party_id')
            ->whereIn('c.id', $lineas->pluck('customer_id')->merge($bookings->pluck('customer_id'))->unique())
            ->pluck('t.name', 'c.id');

        $res = [];
        foreach ($nombres as $clienteId => $nombre) {
            $l = $lineas->where('customer_id', $clienteId);
            $bk = $bookings->where('customer_id', $clienteId);
            $cerrado = $bk->sum('shipped_boxes') > 0;
            $esCont = fn ($x) => str_ends_with((string) $x->cargo_type, '_CONTENEDOR');

            // Contenedores: los embarcados del Cierre; si aún no hay cierre, los de la distribución
            $contenedores = $cerrado
                ? (int) $bk->where('cargo_mode', 'CONTENEDOR')->sum('containers_qty')
                : (float) $l->filter($esCont)->sum('container_quantity');
            $cajasCont = (float) $l->filter($esCont)->sum('box_quantity');
            $cajasContFlete = (float) $l->filter(fn ($x) => $esCont($x) && in_array($x->incoterm, self::INCOTERMS_CON_FLETE))->sum('box_quantity');
            $parteFlete = $cajasCont > 0 ? $cajasContFlete / $cajasCont : 0;

            $res[] = [
                'customer_id'             => (int) $clienteId,
                'name'                    => $nombre,
                'fuente'                  => $cerrado ? 'CIERRE' : 'DISTRIBUCION',
                'cajas'                   => $cerrado ? (int) $bk->sum('shipped_boxes') : (int) $l->sum('box_quantity'),
                'cajas_bajo_cubierta'     => $cerrado ? (int) $bk->where('cargo_mode', 'BODEGA')->sum('shipped_boxes') : (int) $l->reject($esCont)->sum('box_quantity'),
                'contenedores'            => round($contenedores, 2),
                'contenedores_con_flete'  => round($contenedores * $parteFlete, 2), // CFR/CIF
                // Venta: precio promedio de sus líneas del pedido en este barco × cajas
                'cajas_planificadas'      => (int) $l->sum('box_quantity'),
                'cajas_con_precio'        => (int) $l->whereNotNull('box_price')->sum('box_quantity'),
                'venta_planificada'       => round($l->whereNotNull('box_price')->sum(fn ($x) => $x->box_quantity * $x->box_price), 2),
            ];
        }

        return $res;
    }

    private function gananciaDelViaje(Shipment $b): array
    {
        $carga = collect($this->cargaPorCliente($b))->keyBy('customer_id');
        $costo = ShipmentCost::where('shipment_id', $b->id)->first();
        $gastos = ShipmentExpense::where('shipment_id', $b->id)->get();
        $compras = $this->comprasDelViaje($b->id);

        // Clientes: los que tienen carga o compras en este barco
        $ids = $carga->keys()->merge($compras->pluck('customer_id'))->unique()->values();
        $nombres = DB::table('customers as c')->join('third_parties as t', 't.id', '=', 'c.third_party_id')
            ->whereIn('c.id', $ids)->pluck('t.name', 'c.id');

        $totalCajas = $carga->sum('cajas');
        $totalBajo = $carga->sum('cajas_bajo_cubierta');
        $gastosViaje = $gastos->whereNull('customer_id');
        $tarifa = $costo?->container_rate;
        $avisosViaje = [];
        if ($carga->sum('contenedores_con_flete') > 0 && $tarifa === null) {
            $avisosViaje[] = 'FALTA LA TARIFA POR CONTENEDOR DE ESTA NAVE.';
        }
        if ($totalBajo > 0 && $costo?->under_deck_amount === null) {
            $avisosViaje[] = 'FALTA EL FLETE BAJO CUBIERTA (SI NO SE COBRA, PON 0).';
        }

        $clientes = $ids->map(function ($cid) use ($carga, $compras, $gastos, $gastosViaje, $totalCajas, $totalBajo, $costo, $tarifa, $nombres) {
            $c = $carga->get($cid);
            $avisos = [];
            $cajas = $c['cajas'] ?? 0;

            // Venta
            $venta = 0;
            $precioProm = null;
            if ($c) {
                if ($c['cajas_con_precio'] > 0) {
                    $precioProm = $c['venta_planificada'] / $c['cajas_con_precio'];
                }
                if ($c['cajas_con_precio'] < $c['cajas_planificadas']) {
                    $avisos[] = number_format($c['cajas_planificadas'] - $c['cajas_con_precio'], 0, ',', '.') . ' CAJAS DEL PEDIDO SIN PRECIO DE VENTA (SPOT PENDIENTE).';
                }
                $venta = $precioProm === null ? 0 : $cajas * $precioProm;
            }

            // Compras sin carga en el barco: falta la distribución (no es una pérdida real)
            if ($cajas === 0 && $compras->where('customer_id', $cid)->sum('cajas') > 0) {
                $avisos[] = 'TIENE COMPRAS DE FRUTA PERO NINGUNA CAJA EN LA DISTRIBUCIÓN NI EN EL CIERRE DE ESTE BARCO.';
            }

            // Fruta, materiales y transporte descontado al productor
            $cs = $compras->where('customer_id', $cid);
            $fruta = $cs->sum('fruta');
            if ($cs->contains(fn ($x) => $x['sin_precio'])) {
                $avisos[] = 'HAY COMPRAS DE FRUTA SIN PRECIO REAL.';
            }

            // Fletes
            $contFlete = $c['contenedores_con_flete'] ?? 0;
            $fleteCont = $tarifa === null ? 0 : $contFlete * (float) $tarifa;
            $fleteBajo = ($totalBajo > 0 && $costo?->under_deck_amount !== null)
                ? (float) $costo->under_deck_amount * (($c['cajas_bajo_cubierta'] ?? 0) / $totalBajo) : 0;

            // Otros gastos: los del cliente + su parte (por cajas) de los de todo el viaje
            $parte = $totalCajas > 0 ? $cajas / $totalCajas : 0;
            $detalle = [];
            foreach ($gastos->where('customer_id', $cid) as $g) {
                $detalle[$g->category] = ($detalle[$g->category] ?? 0) + (float) $g->amount;
            }
            foreach ($gastosViaje as $g) {
                $detalle[$g->category] = ($detalle[$g->category] ?? 0) + (float) $g->amount * $parte;
            }
            $detalle = array_map(fn ($v) => round($v, 2), $detalle);
            $otros = array_sum($detalle);

            $fila = [
                'customer_id'            => (int) $cid,
                'name'                   => $nombres[$cid] ?? '',
                'fuente'                 => $c['fuente'] ?? 'SIN CARGA',
                'cajas'                  => $cajas,
                'precio_promedio'        => $precioProm === null ? null : round($precioProm, 4),
                'venta'                  => round($venta, 2),
                'cajas_compradas'        => (int) $cs->sum('cajas'),
                'fruta'                  => round($fruta, 2),
                'materiales'             => round($cs->sum('materiales'), 2),
                'materiales_recuperados' => round($cs->sum('materiales_recuperados'), 2),
                'transporte_recuperado'  => round($cs->sum('transporte'), 2),
                'contenedores'           => $c['contenedores'] ?? 0,
                'contenedores_con_flete' => $contFlete,
                'flete_contenedor'       => round($fleteCont, 2),
                'flete_bajo_cubierta'    => round($fleteBajo, 2),
                'gastos'                 => round($otros, 2),
                'gastos_detalle'         => $detalle,
                'avisos'                 => $avisos,
            ];
            $fila['ganancia'] = round($fila['venta'] - $fila['fruta'] - $fila['materiales'] + $fila['materiales_recuperados']
                - $fila['flete_contenedor'] - $fila['flete_bajo_cubierta'] - $fila['gastos'] + $fila['transporte_recuperado'], 2);
            $fila['margen'] = $fila['venta'] > 0 ? round($fila['ganancia'] / $fila['venta'] * 100, 2) : null;

            return $fila;
        })->values();

        $campos = ['cajas', 'venta', 'fruta', 'materiales', 'materiales_recuperados', 'transporte_recuperado', 'flete_contenedor', 'flete_bajo_cubierta', 'gastos', 'ganancia'];
        $total = collect($campos)->mapWithKeys(fn ($k) => [$k => round($clientes->sum($k), 2)])->all();
        $total['margen'] = $total['venta'] > 0 ? round($total['ganancia'] / $total['venta'] * 100, 2) : null;
        $total['incompleto'] = (bool) $avisosViaje || $clientes->contains(fn ($c) => $c['avisos']);

        return $this->datosBarco($b) + [
            'costs'     => ['container_rate' => $tarifa, 'under_deck_amount' => $costo?->under_deck_amount],
            'customers' => $clientes,
            'total'     => $total,
            'avisos'    => $avisosViaje,
        ];
    }

    /**
     * Compras de fruta de este barco (por los bloques de Compra de fruta), con:
     *   cajas  = pagadas según tarjas (embarcadas + caídas); sin tarjas, las compradas
     *   fruta  = cajas × precio real
     *   materiales / materiales_recuperados = parte de lo despachado a ese cupo (repartido entre sus
     *            compras por cajas); recuperado = lo cobrable al productor (se le descuenta al liquidar)
     *   transporte = transporte negociado que se le descuenta al productor
     */
    private function comprasDelViaje(int $shipmentId)
    {
        $lineas = DB::table('producer_quota_lines as l')
            ->join('purchase_blocks as pb', 'pb.id', '=', 'l.purchase_block_id')
            ->where('pb.shipment_id', $shipmentId)
            ->select('l.id', 'l.producer_quota_id', 'pb.customer_id', 'l.quantity_cajas', 'l.real_price', 'l.transport_deduction')
            ->selectSub(DB::table('tally_sheets')->whereColumn('producer_quota_line_id', 'l.id')->selectRaw('COUNT(*)'), 'n_tarjas')
            ->selectSub(DB::table('tally_sheets')->whereColumn('producer_quota_line_id', 'l.id')->selectRaw('COALESCE(SUM(boxes_shipped + boxes_fallen), 0)'), 'pagadas')
            ->get();
        if ($lineas->isEmpty()) {
            return collect();
        }

        // Materiales por cupo (despachado − devuelto, al costo con que salió) y su reparto por cajas
        $cupos = $lineas->pluck('producer_quota_id')->unique();
        $mat = DB::table('inventory_movement_lines as ml')
            ->join('inventory_movements as m', 'm.id', '=', 'ml.inventory_movement_id')
            ->join('supplies as s', 's.id', '=', 'ml.supply_id')
            ->join('supply_categories as sc', 'sc.id', '=', 's.supply_category_id')
            ->whereIn('m.producer_quota_id', $cupos)
            ->where('m.status', true)
            ->whereIn('m.type', ['EGRESO', 'DEVOLUCION'])
            ->groupBy('m.producer_quota_id')
            ->selectRaw("m.producer_quota_id,
                SUM(CASE WHEN m.type = 'EGRESO' THEN ml.total ELSE -ml.total END) as total,
                SUM(CASE WHEN sc.chargeable_to_producer THEN (CASE WHEN m.type = 'EGRESO' THEN ml.total ELSE -ml.total END) ELSE 0 END) as cobrable")
            ->get()->keyBy('producer_quota_id');
        $cajasCupo = DB::table('producer_quota_lines')->whereIn('producer_quota_id', $cupos)->whereNotNull('customer_id')
            ->groupBy('producer_quota_id')->selectRaw('producer_quota_id, SUM(quantity_cajas) as cajas')->pluck('cajas', 'producer_quota_id');

        return $lineas->map(function ($l) use ($mat, $cajasCupo) {
            $cajas = $l->n_tarjas > 0 ? (int) $l->pagadas : (int) $l->quantity_cajas;
            $parte = ($cajasCupo[$l->producer_quota_id] ?? 0) > 0 ? $l->quantity_cajas / $cajasCupo[$l->producer_quota_id] : 0;
            $m = $mat->get($l->producer_quota_id);
            return [
                'customer_id'            => (int) $l->customer_id,
                'cajas'                  => $cajas,
                'fruta'                  => $cajas * (float) ($l->real_price ?? 0),
                'sin_precio'             => $cajas > 0 && $l->real_price === null,
                'materiales'             => $m ? max(0, (float) $m->total) * $parte : 0,
                'materiales_recuperados' => $m ? max(0, (float) $m->cobrable) * $parte : 0,
                'transporte'             => $cajas > 0 ? (float) $l->transport_deduction : 0,
            ];
        });
    }
}
