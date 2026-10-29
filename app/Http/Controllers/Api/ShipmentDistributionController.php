<?php

// FASE 2 — Distribución del pedido semanal en barcos (Logística)
// Ubicación: app/Http/Controllers/Api/ShipmentDistributionController.php
//
// Logística arma los barcos de la semana (naviera, barco, puerto de salida, destino, fechas)
// y reparte cada línea del pedido entre ellos. Una línea puede ir en varios barcos.
// Se guarda TODO el reparto de la semana de una vez (como una hoja):
//   GET /customer-orders/{id}/distribution → pedido + barcos + reparto
//   PUT /customer-orders/{id}/distribution → reemplaza el reparto
// Se permite guardar a medias (lo pendiente se ve en pantalla); lo que no se permite es
// asignar MÁS cajas de las que tiene la línea.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ControlVersion;
use App\Http\Controllers\Controller;
use App\Models\CustomerOrder;
use App\Models\Port;
use App\Models\PurchaseBlock;
use App\Models\Shipment;
use App\Models\ShipmentBooking;
use App\Models\Vessel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShipmentDistributionController extends Controller
{
    use ControlVersion;

    public function show($orderId)
    {
        $pedido = CustomerOrder::findOrFail($orderId);

        return response()->json($this->cargar($pedido));
    }

    public function update(Request $request, $orderId)
    {
        $pedido = CustomerOrder::with('lines')->findOrFail($orderId);

        if (!$pedido->status) {
            return response()->json(['message' => 'EL PEDIDO ESTÁ ANULADO: NO SE PUEDE DISTRIBUIR.'], 422);
        }

        $data = $request->validate([
            'shipments'                                 => 'present|array',
            'shipments.*.id'                            => 'nullable|integer',
            'shipments.*.shipping_line_id'              => 'required|exists:shipping_lines,id',
            'shipments.*.vessel_id'                     => 'required|exists:vessels,id',
            'shipments.*.departure_port_id'             => 'required|exists:ports,id',
            'shipments.*.destination_port_id'           => 'nullable|exists:ports,id',
            'shipments.*.departure_date'                => 'required|date',
            'shipments.*.eta'                           => 'nullable|date',
            'shipments.*.voyage'                        => 'nullable|string|max:20', // N° de viaje (UNI1401)
            'shipments.*.comment'                       => 'nullable|string|max:500',
            'shipments.*.allocations'                   => 'present|array',
            'shipments.*.allocations.*.customer_order_line_id' => 'required|integer',
            'shipments.*.allocations.*.boxes'           => 'required|integer|min:1',
        ], [
            'shipments.*.shipping_line_id.required'  => 'CADA BARCO DEBE TENER NAVIERA.',
            'shipments.*.vessel_id.required'         => 'CADA BARCO DEBE TENER EL NOMBRE DEL BARCO.',
            'shipments.*.departure_port_id.required' => 'CADA BARCO DEBE TENER PUERTO DE SALIDA.',
            'shipments.*.departure_date.required'    => 'CADA BARCO DEBE TENER FECHA DE SALIDA.',
            'shipments.*.allocations.*.boxes.min'    => 'LAS CAJAS ASIGNADAS DEBEN SER MAYORES A CERO.',
        ]);

        $lineas = $pedido->lines->keyBy('id');
        $numeroLinea = $pedido->lines->sortBy('id')->values()->pluck('id')->flip(); // id → posición (0..n)
        $errores = [];
        $asignadoPorLinea = [];

        foreach ($data['shipments'] as $i => $barco) {
            $n = $i + 1;
            $vessel = Vessel::find($barco['vessel_id']);
            if ((int) $vessel->shipping_line_id !== (int) $barco['shipping_line_id']) {
                $errores["shipments.$i.vessel_id"] = "BARCO $n: {$vessel->name} NO PERTENECE A LA NAVIERA ELEGIDA.";
            }
            if (Port::find($barco['departure_port_id'])?->port_type !== 'SALIDA') {
                $errores["shipments.$i.departure_port_id"] = "BARCO $n: EL PUERTO DE SALIDA DEBE SER DE ECUADOR (SALIDA).";
            }
            if (!empty($barco['destination_port_id']) && Port::find($barco['destination_port_id'])?->port_type !== 'DESTINO') {
                $errores["shipments.$i.destination_port_id"] = "BARCO $n: EL PUERTO DE DESTINO DEBE SER DE TIPO DESTINO.";
            }
            if (!empty($barco['eta']) && $barco['eta'] < $barco['departure_date']) {
                $errores["shipments.$i.eta"] = "BARCO $n: LA LLEGADA (ETA) NO PUEDE SER ANTES DE LA SALIDA.";
            }

            $vistas = [];
            foreach ($barco['allocations'] as $a) {
                $linea = $lineas->get($a['customer_order_line_id']);
                if (!$linea) {
                    $errores["shipments.$i.allocations"] = "BARCO $n: HAY UNA LÍNEA QUE NO PERTENECE A ESTE PEDIDO.";
                    continue;
                }
                $nl = $numeroLinea[$linea->id] + 1;
                if (isset($vistas[$linea->id])) {
                    $errores["shipments.$i.allocations"] = "BARCO $n: LA LÍNEA $nl ESTÁ REPETIDA.";
                }
                $vistas[$linea->id] = true;

                // Contenedor: se embarcan contenedores completos
                if ($linea->boxes_per_container && $a['boxes'] % $linea->boxes_per_container !== 0) {
                    $errores["shipments.$i.allocations.$nl"] = "BARCO $n, LÍNEA $nl: {$a['boxes']} CAJAS NO SON CONTENEDORES COMPLETOS DE {$linea->boxes_per_container}.";
                }
                $asignadoPorLinea[$linea->id] = ($asignadoPorLinea[$linea->id] ?? 0) + $a['boxes'];
            }
        }

        foreach ($asignadoPorLinea as $lineaId => $total) {
            $linea = $lineas[$lineaId];
            if ($total > $linea->boxes) {
                $nl = $numeroLinea[$lineaId] + 1;
                $errores["lines.$nl"] = "LÍNEA $nl: SE ASIGNAN " . number_format($total, 0, ',', '.') . ' CAJAS EN BARCOS PERO LA LÍNEA TIENE ' . number_format($linea->boxes, 0, ',', '.') . '.';
            }
        }

        // Un barco con bloques de compra de Comercial no se quita (primero se quitan sus compras)
        $recibidos = collect($data['shipments'])->pluck('id')->filter()->all();
        $conCompras = PurchaseBlock::where('customer_order_id', $pedido->id)
            ->whereNotIn('shipment_id', $recibidos)->with('shipment:id,vessel_name,voyage')->get()->pluck('shipment')->unique('id');
        foreach ($conCompras as $barco) {
            $errores["shipments.q{$barco->id}"] = "NO SE PUEDE QUITAR EL BARCO {$barco->vessel_name}: COMERCIAL YA TIENE COMPRAS EN ÉL.";
        }

        // Ni un barco con bookings en el Cierre de embarque (29/10/2026)
        $conCierre = ShipmentBooking::whereIn('shipment_id', Shipment::where('customer_order_id', $pedido->id)->where('status', true)->select('id'))
            ->whereNotIn('shipment_id', $recibidos)->with('shipment:id,vessel_name')->get()->pluck('shipment')->unique('id');
        foreach ($conCierre as $barco) {
            $errores["shipments.b{$barco->id}"] = "NO SE PUEDE QUITAR EL BARCO {$barco->vessel_name}: YA TIENE BOOKINGS EN EL CIERRE DE EMBARQUE.";
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        DB::transaction(function () use ($pedido, $data, $lineas, $request) {
            // Bloquea la semana y revisa que nadie haya guardado mientras se editaba
            CustomerOrder::whereKey($pedido->id)->lockForUpdate()->first();
            $this->verificarVersion($request, $this->version($pedido->id));

            $existentes = Shipment::where('customer_order_id', $pedido->id)->where('status', true)->get()->keyBy('id');
            $idsRecibidos = [];

            foreach ($data['shipments'] as $barco) {
                $vessel = Vessel::find($barco['vessel_id']);
                $valores = [
                    'shipping_line_id'    => $barco['shipping_line_id'],
                    'vessel_id'           => $vessel->id,
                    'vessel_name'         => $vessel->name,
                    'departure_port_id'   => $barco['departure_port_id'],
                    'destination_port_id' => $barco['destination_port_id'] ?? null,
                    'departure_date'      => $barco['departure_date'],
                    'eta'                 => $barco['eta'] ?? null,
                    'voyage'              => !empty($barco['voyage']) ? mb_strtoupper(trim($barco['voyage'])) : null,
                    'comment'             => !empty($barco['comment']) ? mb_strtoupper(trim($barco['comment'])) : null,
                    'week_start'          => $pedido->week,
                    'week_end'            => $pedido->week,
                    'year'                => $pedido->year,
                ];

                $actual = !empty($barco['id']) ? $existentes->get($barco['id']) : null;
                if ($actual) {
                    $actual->update($valores);
                    $shipment = $actual;
                } else {
                    $shipment = Shipment::create($valores + [
                        'code'              => $this->siguienteCodigo(),
                        'registration_date' => now()->toDateString(),
                        'customer_order_id' => $pedido->id,
                        'created_by'        => $request->user()?->id,
                        'status'            => true,
                    ]);
                }
                $idsRecibidos[] = $shipment->id;

                // El reparto de este barco se reemplaza completo
                $shipment->lines()->delete();
                foreach ($barco['allocations'] as $a) {
                    $linea = $lineas[$a['customer_order_line_id']];
                    $esContenedor = str_ends_with($linea->cargo_type, '_CONTENEDOR');
                    $shipment->lines()->create([
                        'customer_order_line_id' => $linea->id,
                        'customer_id'            => $linea->customer_id,
                        'week'                   => $pedido->week,
                        'destination_port_id'    => $barco['destination_port_id'] ?? null,
                        'arrival_date'           => $barco['eta'] ?? null,
                        'type'                   => $esContenedor ? 'CONTENEDOR' : 'CAJA',
                        'container_quantity'     => $esContenedor && $linea->boxes_per_container ? intdiv($a['boxes'], $linea->boxes_per_container) : 0,
                        'box_quantity'           => $a['boxes'],
                        'incoterm'               => $linea->incoterm,
                        'negotiation_type'       => $linea->negotiation_type,
                        'status'                 => true,
                    ]);
                }
            }

            // Barcos que Logística quitó: baja lógica (nunca DELETE) y sin reparto
            foreach ($existentes->except($idsRecibidos) as $quitado) {
                $quitado->lines()->delete();
                $quitado->update(['status' => false]);
            }
        });

        return response()->json($this->cargar($pedido->fresh()));
    }

    // Pedido + líneas (con lo ya asignado) + barcos activos con su reparto
    private function cargar(CustomerOrder $pedido): array
    {
        $pedido->load([
            'lines' => fn ($q) => $q->orderBy('id'),
            'lines.customer.thirdParty:id,name',
            'lines.departurePort:id,name',
        ]);

        $barcos = Shipment::with([
                'shippingLine:id,name',
                'vessel:id,name,shipping_line_id',
                'departurePort:id,name',
                'destinationPort:id,name',
                'lines:id,shipment_id,customer_order_line_id,box_quantity,container_quantity',
            ])
            ->where('customer_order_id', $pedido->id)
            ->where('status', true)
            ->orderBy('departure_date')
            ->orderBy('id')
            ->get();

        return ['order' => $pedido, 'shipments' => $barcos, 'version' => $this->version($pedido->id)];
    }

    // Huella de barcos + reparto de la semana (control de cambios simultáneos)
    private function version(int $pedidoId): string
    {
        $barcos = DB::table('shipments')->where('customer_order_id', $pedidoId);
        $reparto = DB::table('shipment_lines')
            ->whereIn('shipment_id', DB::table('shipments')->where('customer_order_id', $pedidoId)->select('id'));

        return $this->huella([$barcos, $reparto]);
    }

    // EMB-00000001, correlativo. Se bloquea la tabla para que dos personas no tomen el mismo código.
    private function siguienteCodigo(): string
    {
        $ultimo = Shipment::lockForUpdate()->orderByDesc('id')->value('code');
        $siguiente = $ultimo && preg_match('/(\d+)$/', $ultimo, $m) ? (int) $m[1] + 1 : 1;

        return 'EMB-' . str_pad($siguiente, 8, '0', STR_PAD_LEFT);
    }
}
