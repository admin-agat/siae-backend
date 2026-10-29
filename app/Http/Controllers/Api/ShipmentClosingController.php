<?php

// CIERRE DE EMBARQUE (Exportaciones) — 29/10/2026
// Ubicación: app/Http/Controllers/Api/ShipmentClosingController.php
//
// La hoja de Logística "Asignación de carga WK-xx": por cada barco de la semana, sus bookings con
// lo REALMENTE embarcado y sus documentos (booking, DAE, B/L, AUCP, orden, Forma A, consignee,
// notify, estado de la DAE). La distribución en barcos es el plan; esto es lo que pasó:
//   - se puede embarcar más o menos que el cupo (en la WK37 se embarcaron +290 en bodega);
//   - un contenedor puede salir incompleto (16 contenedores = 19.199 cajas);
//   - lo que falta de un CONTRATO se completa con SPOT en otro booking (la meta es embarcar la
//     mayor cantidad de fruta). Esas cajas son SPOT.
// INGRESO = cajas de la tarja del puerto; INGRESO − EMBARCADO = lo que no subió (caídas + rechazos).
// Se cruza con la suma de las tarjas registradas (Liquidación) por barco + cliente + marca.
//
//   GET /customer-orders/{id}/closing → pedido, clientes, barcos con su plan, bookings y tarjas
//   PUT /customer-orders/{id}/closing → reemplaza los bookings de la semana (con control de versión)

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ControlVersion;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Shipment;
use App\Models\ShipmentBooking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShipmentClosingController extends Controller
{
    use ControlVersion;

    public function show($orderId)
    {
        return response()->json($this->cargar(CustomerOrder::findOrFail($orderId)));
    }

    public function update(Request $request, $orderId)
    {
        $pedido = CustomerOrder::with('lines')->findOrFail($orderId);

        if (!$pedido->status) {
            return response()->json(['message' => 'EL PEDIDO ESTÁ ANULADO: NO SE PUEDE CERRAR EL EMBARQUE.'], 422);
        }

        $data = $request->validate([
            'shipments'                               => 'present|array',
            'shipments.*.id'                          => 'required|integer',
            'shipments.*.loading_from'                => 'nullable|date',
            'shipments.*.loading_to'                  => 'nullable|date',
            'shipments.*.departure_date'              => 'required|date',
            'shipments.*.eta'                         => 'nullable|date',
            'shipments.*.bookings'                    => 'present|array',
            'shipments.*.bookings.*.id'               => 'nullable|integer',
            'shipments.*.bookings.*.customer_id'      => 'required|exists:customers,id',
            'shipments.*.bookings.*.brand_id'         => 'nullable|exists:brands,id',
            'shipments.*.bookings.*.negotiation_type' => 'required|in:' . implode(',', ShipmentBooking::TIPOS),
            'shipments.*.bookings.*.cargo_mode'       => 'required|in:' . implode(',', ShipmentBooking::MODALIDADES),
            'shipments.*.bookings.*.holds'            => 'nullable|string|max:40',
            'shipments.*.bookings.*.containers_qty'   => 'nullable|integer|min:0|max:500',
            'shipments.*.bookings.*.booking_number'   => 'nullable|string|max:30',
            'shipments.*.bookings.*.dae_number'       => 'nullable|string|max:30',
            'shipments.*.bookings.*.bl_number'        => 'nullable|string|max:30',
            'shipments.*.bookings.*.aucp'             => 'nullable|string|max:20',
            'shipments.*.bookings.*.order_ref'        => 'nullable|string|max:20',
            'shipments.*.bookings.*.forma_a'          => 'nullable|string|max:60',
            'shipments.*.bookings.*.consignee_name'   => 'nullable|string|max:150',
            'shipments.*.bookings.*.consignee_address'=> 'nullable|string|max:500',
            'shipments.*.bookings.*.notify_name'      => 'nullable|string|max:150',
            'shipments.*.bookings.*.notify_address'   => 'nullable|string|max:500',
            'shipments.*.bookings.*.quota_boxes'      => 'required|integer|min:0',
            'shipments.*.bookings.*.shipped_boxes'    => 'required|integer|min:0',
            'shipments.*.bookings.*.port_entry_boxes' => 'nullable|integer|min:0',
            'shipments.*.bookings.*.sample_boxes'     => 'nullable|integer|min:0',
            'shipments.*.bookings.*.net_weight_per_box'   => 'nullable|numeric|min:0|max:99',
            'shipments.*.bookings.*.gross_weight_per_box' => 'nullable|numeric|min:0|max:99',
            'shipments.*.bookings.*.dae_status'       => 'nullable|string|max:40',
            'shipments.*.bookings.*.arrival_note'     => 'nullable|string|max:40',
            'shipments.*.bookings.*.notes'            => 'nullable|string|max:255',
        ], [
            'shipments.*.departure_date.required'        => 'CADA BARCO DEBE TENER FECHA DE ZARPE.',
            'shipments.*.bookings.*.customer_id.required' => 'CADA BOOKING DEBE TENER CLIENTE.',
            'shipments.*.bookings.*.quota_boxes.required' => 'CADA BOOKING DEBE TENER CUPO (0 SI NO HABÍA).',
            'shipments.*.bookings.*.shipped_boxes.required' => 'CADA BOOKING DEBE TENER LAS CAJAS EMBARCADAS (0 SI AÚN NO EMBARCA).',
        ]);

        $barcos = Shipment::where('customer_order_id', $pedido->id)->where('status', true)->get()->keyBy('id');
        $clientesPedido = $pedido->lines->pluck('customer_id')->unique()->flip();
        $marcasPorCliente = Customer::with('brands:id')->whereIn('id', $clientesPedido->keys())->get()
            ->mapWithKeys(fn ($c) => [$c->id => $c->brands->pluck('id')->all()]);
        $actuales = ShipmentBooking::whereIn('shipment_id', $barcos->keys())->get()->keyBy('id');

        $errores = [];
        $bookingsVistos = [];
        foreach ($data['shipments'] as $i => $b) {
            $barco = $barcos->get($b['id']);
            if (!$barco) {
                $errores["shipments.$i"] = 'HAY UN BARCO QUE NO ES DE ESTA SEMANA (ARMA LOS BARCOS EN DISTRIBUCIÓN EN BARCOS).';
                continue;
            }
            $nombre = $barco->vessel_name;
            if (!empty($b['loading_from']) && !empty($b['loading_to']) && $b['loading_to'] < $b['loading_from']) {
                $errores["shipments.$i.loading_to"] = "$nombre: LA CARGA NO PUEDE TERMINAR ANTES DE EMPEZAR.";
            }
            if (!empty($b['eta']) && $b['eta'] < $b['departure_date']) {
                $errores["shipments.$i.eta"] = "$nombre: LA LLEGADA (ETA) NO PUEDE SER ANTES DEL ZARPE.";
            }

            foreach ($b['bookings'] as $j => $k) {
                $fila = "$nombre, FILA " . ($j + 1);
                if (!empty($k['id']) && (int) ($actuales[$k['id']]->shipment_id ?? 0) !== (int) $barco->id) {
                    $errores["shipments.$i.bookings.$j"] = "$fila: EL BOOKING NO EXISTE O NO ES DE ESTE BARCO.";
                }
                if (!$clientesPedido->has($k['customer_id'])) {
                    $errores["shipments.$i.bookings.$j.customer_id"] = "$fila: ESE CLIENTE NO ESTÁ EN EL PEDIDO DE ESTA SEMANA.";
                } elseif (!empty($k['brand_id']) && !in_array((int) $k['brand_id'], $marcasPorCliente[$k['customer_id']] ?? [])) {
                    $errores["shipments.$i.bookings.$j.brand_id"] = "$fila: LA MARCA NO PERTENECE A ESE CLIENTE.";
                }
                if ($k['cargo_mode'] === 'CONTENEDOR' && empty($k['containers_qty'])) {
                    $errores["shipments.$i.bookings.$j.containers_qty"] = "$fila: INDICA CUÁNTOS CONTENEDORES.";
                }
                $numero = mb_strtoupper(trim($k['booking_number'] ?? ''));
                if ($numero !== '') {
                    if (isset($bookingsVistos[$numero])) {
                        $errores["shipments.$i.bookings.$j.booking_number"] = "$fila: EL BOOKING $numero ESTÁ REPETIDO.";
                    }
                    $bookingsVistos[$numero] = true;
                }
            }
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        DB::transaction(function () use ($request, $pedido, $data, $barcos, $actuales) {
            // Bloquea la semana y revisa que nadie haya guardado mientras se editaba
            CustomerOrder::whereKey($pedido->id)->lockForUpdate()->first();
            $this->verificarVersion($request, $this->version($pedido->id));

            $mayus = fn ($v) => isset($v) && trim((string) $v) !== '' ? mb_strtoupper(trim($v)) : null;
            $guardados = [];

            foreach ($data['shipments'] as $b) {
                $barcos[$b['id']]->update([
                    'loading_from'   => $b['loading_from'] ?? null,
                    'loading_to'     => $b['loading_to'] ?? null,
                    'departure_date' => $b['departure_date'],
                    'eta'            => $b['eta'] ?? null,
                ]);

                foreach ($b['bookings'] as $k) {
                    $contenedor = $k['cargo_mode'] === 'CONTENEDOR';
                    $valores = [
                        'shipment_id'          => $b['id'],
                        'customer_id'          => $k['customer_id'],
                        'brand_id'             => $k['brand_id'] ?? null,
                        'negotiation_type'     => $k['negotiation_type'],
                        'cargo_mode'           => $k['cargo_mode'],
                        'holds'                => $contenedor ? null : $mayus($k['holds'] ?? null),
                        'containers_qty'       => $contenedor ? (int) $k['containers_qty'] : 0,
                        'booking_number'       => $mayus($k['booking_number'] ?? null),
                        'dae_number'           => $mayus($k['dae_number'] ?? null),
                        'bl_number'            => $mayus($k['bl_number'] ?? null),
                        'aucp'                 => $mayus($k['aucp'] ?? null),
                        'order_ref'            => $mayus($k['order_ref'] ?? null),
                        'forma_a'              => $mayus($k['forma_a'] ?? null),
                        // Nombres y direcciones del exterior: se guardan como se escriben
                        'consignee_name'       => $this->limpio($k['consignee_name'] ?? null),
                        'consignee_address'    => $this->limpio($k['consignee_address'] ?? null),
                        'notify_name'          => $this->limpio($k['notify_name'] ?? null),
                        'notify_address'       => $this->limpio($k['notify_address'] ?? null),
                        'quota_boxes'          => (int) $k['quota_boxes'],
                        'shipped_boxes'        => (int) $k['shipped_boxes'],
                        'port_entry_boxes'     => isset($k['port_entry_boxes']) ? (int) $k['port_entry_boxes'] : null,
                        'sample_boxes'         => (int) ($k['sample_boxes'] ?? 0),
                        'net_weight_per_box'   => $k['net_weight_per_box'] ?? null,
                        'gross_weight_per_box' => $k['gross_weight_per_box'] ?? null,
                        'dae_status'           => $mayus($k['dae_status'] ?? null),
                        'arrival_note'         => $mayus($k['arrival_note'] ?? null),
                        'notes'                => $mayus($k['notes'] ?? null),
                    ];
                    $actual = !empty($k['id']) ? $actuales->get($k['id']) : null;
                    if ($actual) {
                        $actual->update($valores);
                        $guardados[] = $actual->id;
                    } else {
                        $guardados[] = ShipmentBooking::create($valores + ['created_by' => $request->user()?->id])->id;
                    }
                }
            }

            // Bookings quitados de los barcos que se enviaron
            ShipmentBooking::whereIn('shipment_id', collect($data['shipments'])->pluck('id'))
                ->whereNotIn('id', $guardados)->delete();
        });

        return response()->json($this->cargar($pedido->fresh()));
    }

    private function limpio(?string $v): ?string
    {
        return isset($v) && trim($v) !== '' ? trim($v) : null;
    }

    private function version(int $pedidoId): string
    {
        $barcos = DB::table('shipments')->where('customer_order_id', $pedidoId);
        $bookings = DB::table('shipment_bookings')
            ->whereIn('shipment_id', DB::table('shipments')->where('customer_order_id', $pedidoId)->select('id'));

        return $this->huella([$barcos, $bookings]);
    }

    private function cargar(CustomerOrder $pedido): array
    {
        $pedido->load(['lines:id,customer_order_id,customer_id,boxes,negotiation_type,cargo_type']);

        $clientes = Customer::with(['thirdParty:id,name', 'brands:id,name,code,status'])
            ->whereIn('id', $pedido->lines->pluck('customer_id')->unique())
            ->get()
            ->map(fn ($c) => [
                'id'            => $c->id,
                'name'          => $c->thirdParty?->name,
                'brands'        => $c->brands->values(),
                'pedido'        => (int) $pedido->lines->where('customer_id', $c->id)->sum('boxes'),
                'pedido_contrato' => (int) $pedido->lines->where('customer_id', $c->id)->where('negotiation_type', 'CONTRATO')->sum('boxes'),
                'pedido_spot'   => (int) $pedido->lines->where('customer_id', $c->id)->where('negotiation_type', 'SPOT')->sum('boxes'),
            ])
            ->values();

        $barcos = Shipment::with([
                'shippingLine:id,name',
                'departurePort:id,name',
                'destinationPort:id,name',
                'lines:id,shipment_id,customer_id,box_quantity,container_quantity,negotiation_type',
                'bookings' => fn ($q) => $q->orderBy('id'),
            ])
            ->where('customer_order_id', $pedido->id)
            ->where('status', true)
            ->orderBy('departure_date')
            ->orderBy('id')
            ->get();

        // Tarjas registradas (Liquidación) por barco + cliente + marca, para cruzar con el INGRESO
        $tarjas = DB::table('tally_sheets as t')
            ->join('producer_quota_lines as l', 'l.id', '=', 't.producer_quota_line_id')
            ->whereIn('t.shipment_id', $barcos->pluck('id'))
            ->groupBy('t.shipment_id', 'l.customer_id', 'l.brand_id')
            ->selectRaw('t.shipment_id, l.customer_id, l.brand_id,
                SUM(t.boxes_shipped) AS embarcadas, SUM(t.boxes_fallen) AS caidas, SUM(t.boxes_rejected) AS rechazadas')
            ->get();

        // Último consignee/notify usado por cliente: se propone al agregar un booking
        $ultimos = ShipmentBooking::whereNotNull('consignee_name')
            ->orderByDesc('id')->get(['customer_id', 'consignee_name', 'consignee_address', 'notify_name', 'notify_address'])
            ->unique('customer_id')->keyBy('customer_id');

        return [
            'order'     => $pedido->only(['id', 'week', 'year', 'expected_boxes', 'status']),
            'customers' => $clientes,
            'shipments' => $barcos,
            'tallies'   => $tarjas,
            'parties'   => $ultimos,
            'version'   => $this->version($pedido->id),
        ];
    }
}
