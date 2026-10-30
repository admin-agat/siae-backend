<?php

// Controlador del PEDIDO SEMANAL (Exportaciones) — tablas customer_orders + customer_order_lines
// Ubicación: app/Http/Controllers/Api/CustomerOrderController.php
//
// Flujo: Rusia manda por correo el pedido de la semana → Exportaciones lo registra aquí →
// Logística lo distribuye en barcos (Fase 2) → Comercial compra la fruta a productores (Fase 3).
// Reglas:
//   - Un solo pedido por semana/año: las correcciones de Rusia se EDITAN, no se duplican.
//   - CONTRATO: el precio se copia del contrato vigente del cliente (mismo incoterm, o contrato
//     sin incoterm) a la fecha del lunes de esa semana, y queda CONGELADO en la línea.
//   - SPOT: el precio se escribe a mano y puede quedar vacío hasta que llegue.
//   - Contenedor: contenedores × cajas por contenedor debe dar el total de cajas.
//   - expected_boxes: total de la semana que pide Rusia; la suma de las líneas debe cuadrar exacto.
// Patrón del proyecto: deactivate/reactivate (nunca destroy).

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerContract;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderLine;
use App\Models\Port;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerOrderController extends Controller
{
    private const INCOTERMS = ['FOB', 'CFR', 'CIF'];
    private const CARGO_TYPES = ['GRANEL_BAJO_CUBIERTA', 'GRANEL_CONTENEDOR', 'CAJA_BAJO_CUBIERTA', 'CAJA_CONTENEDOR'];

    // GET /customer-orders → pedidos con total de cajas, número de líneas y cajas ya
    // distribuidas en barcos (distributed_boxes, Fase 2)
    public function index(Request $request)
    {
        $query = CustomerOrder::withCount('lines')
            ->withSum('lines as total_boxes', 'boxes')
            ->addSelect(['distributed_boxes' => DB::table('shipment_lines as sl')
                ->join('shipments as s', 's.id', '=', 'sl.shipment_id')
                ->whereColumn('s.customer_order_id', 'customer_orders.id')
                ->where('s.status', true)
                ->selectRaw('COALESCE(SUM(sl.box_quantity), 0)')])
            // Fase 3: cajas ya compradas por Comercial a productores
            ->addSelect(['purchased_boxes' => DB::table('producer_quota_lines as ql')
                ->join('producer_quotas as q', 'q.id', '=', 'ql.producer_quota_id')
                ->whereColumn('q.customer_order_id', 'customer_orders.id')
                ->whereNotNull('ql.customer_id')
                ->selectRaw('COALESCE(SUM(ql.quantity_cajas), 0)')])
            // 29/10/2026: cajas realmente embarcadas según el Cierre de embarque
            ->addSelect(['shipped_boxes' => DB::table('shipment_bookings as sb')
                ->join('shipments as s', 's.id', '=', 'sb.shipment_id')
                ->whereColumn('s.customer_order_id', 'customer_orders.id')
                ->where('s.status', true)
                ->selectRaw('COALESCE(SUM(sb.shipped_boxes), 0)')]);

        if ($request->has('status')) {
            $query->where('status', $request->boolean('status'));
        }

        return response()->json($query->orderByDesc('year')->orderByDesc('week')->get());
    }

    // GET /customer-orders/{id} → pedido con sus líneas completas
    public function show($id)
    {
        return response()->json($this->cargar(CustomerOrder::findOrFail($id)));
    }

    // POST /customer-orders → crear pedido con sus líneas
    public function store(Request $request)
    {
        $data = $this->validar($request);

        $pedido = DB::transaction(function () use ($data) {
            $pedido = CustomerOrder::create([
                'week'        => $data['week'],
                'year'        => $data['year'],
                'expected_boxes' => $data['expected_boxes'],
                'received_at' => $data['received_at'] ?? null,
                'comment'     => $data['comment'],
                'status'      => true,
                'created_by'  => auth()->id(),
            ]);

            $this->guardarLineas($pedido, $data['lines']);

            return $pedido;
        });

        return response()->json($this->cargar($pedido), 201);
    }

    // PUT /customer-orders/{id} → actualizar cabecera y líneas (correcciones de Rusia)
    public function update(Request $request, $id)
    {
        $pedido = CustomerOrder::findOrFail($id);
        $data = $this->validar($request, $pedido->id);

        DB::transaction(function () use ($pedido, $data) {
            $pedido->update([
                'week'        => $data['week'],
                'year'        => $data['year'],
                'expected_boxes' => $data['expected_boxes'],
                'received_at' => $data['received_at'] ?? null,
                'comment'     => $data['comment'],
            ]);

            $this->guardarLineas($pedido, $data['lines']);
        });

        return response()->json($this->cargar($pedido));
    }

    // PATCH /customer-orders/{id}/deactivate → anular el pedido (baja lógica)
    public function deactivate($id)
    {
        $pedido = CustomerOrder::findOrFail($id);
        $pedido->update(['status' => false]);
        return response()->json($pedido);
    }

    // PATCH /customer-orders/{id}/reactivate
    public function reactivate($id)
    {
        $pedido = CustomerOrder::findOrFail($id);
        $pedido->update(['status' => true]);
        return response()->json($pedido);
    }

    // ---------------- Ayudantes privados ----------------

    private function cargar(CustomerOrder $pedido): CustomerOrder
    {
        return $pedido->load([
            'lines' => fn ($q) => $q->orderBy('id'),
            'lines.customer.thirdParty',
            'lines.customer.brands',
            'lines.departurePort',
            'lines.contract',
        ]);
    }

    private function validar(Request $request, ?int $ignorarId = null): array
    {
        $data = $request->validate([
            'week'        => [
                'required', 'integer', 'min:1', 'max:53',
                // Un solo pedido por semana/año
                Rule::unique('customer_orders')->where('year', $request->year)->ignore($ignorarId),
            ],
            'year'        => 'required|integer|min:2020|max:2100',
            'expected_boxes' => 'required|integer|min:1',
            'received_at' => 'nullable|date',
            'comment'     => 'nullable|string',

            'lines'                        => 'required|array|min:1',
            'lines.*.id'                   => 'nullable|integer',
            'lines.*.customer_id'          => 'required|exists:customers,id',
            'lines.*.boxes'                => 'required|integer|min:1',
            'lines.*.incoterm'             => 'required|in:' . implode(',', self::INCOTERMS),
            'lines.*.cargo_type'           => 'required|in:' . implode(',', self::CARGO_TYPES),
            'lines.*.containers_qty'       => 'nullable|integer|min:1',
            'lines.*.boxes_per_container'  => 'nullable|integer|min:1',
            'lines.*.departure_port_id'    => 'required|exists:ports,id',
            'lines.*.negotiation_type'     => 'required|in:CONTRATO,SPOT',
            'lines.*.box_price'            => 'nullable|numeric|min:0',
            'lines.*.order_number'         => 'nullable|string|max:50',
            'lines.*.notes'                => 'nullable|string|max:255',
        ], [
            'week.unique'                        => 'YA EXISTE UN PEDIDO PARA ESA SEMANA. ÁBRELO Y EDÍTALO.',
            'week.required'                      => 'LA SEMANA ES OBLIGATORIA.',
            'week.max'                           => 'LA SEMANA DEBE ESTAR ENTRE 1 Y 53.',
            'expected_boxes.required'            => 'INGRESA EL TOTAL DE CAJAS DE LA SEMANA.',
            'expected_boxes.min'                 => 'EL TOTAL DE CAJAS DE LA SEMANA DEBE SER MAYOR A CERO.',
            'lines.required'                     => 'EL PEDIDO DEBE TENER AL MENOS UNA LÍNEA.',
            'lines.min'                          => 'EL PEDIDO DEBE TENER AL MENOS UNA LÍNEA.',
            'lines.*.customer_id.required'       => 'CADA LÍNEA DEBE TENER CLIENTE.',
            'lines.*.boxes.required'             => 'CADA LÍNEA DEBE TENER CAJAS.',
            'lines.*.boxes.min'                  => 'LAS CAJAS DEBEN SER MAYORES A CERO.',
            'lines.*.departure_port_id.required' => 'CADA LÍNEA DEBE TENER PUERTO DE SALIDA.',
        ]);

        $data['comment'] = !empty($data['comment']) ? mb_strtoupper(trim($data['comment'])) : null;

        // Validaciones de negocio por línea (con el número de línea en el mensaje)
        $lunes = Carbon::now()->setISODate($data['year'], $data['week'])->startOfDay();
        $errores = [];

        foreach ($data['lines'] as $i => &$linea) {
            $n = $i + 1;

            $cliente = Customer::with('thirdParty')->find($linea['customer_id']);
            if (!$cliente->status) {
                $errores["lines.$i.customer_id"] = "LÍNEA $n: EL CLIENTE {$cliente->thirdParty?->name} ESTÁ INACTIVO.";
            }

            $puerto = Port::find($linea['departure_port_id']);
            if ($puerto->port_type !== 'SALIDA') {
                $errores["lines.$i.departure_port_id"] = "LÍNEA $n: {$puerto->name} NO ES UN PUERTO DE SALIDA.";
            }

            // Contenedor: cantidad × cajas por contenedor = cajas
            if (str_ends_with($linea['cargo_type'], '_CONTENEDOR')) {
                $cont = $linea['containers_qty'] ?? null;
                $porCont = $linea['boxes_per_container'] ?? null;
                if (!$cont || !$porCont) {
                    $errores["lines.$i.containers_qty"] = "LÍNEA $n: INDICA CONTENEDORES Y CAJAS POR CONTENEDOR.";
                } elseif ($cont * $porCont !== (int) $linea['boxes']) {
                    $errores["lines.$i.containers_qty"] = "LÍNEA $n: $cont × $porCont = " . ($cont * $porCont) . ", PERO LA LÍNEA TIENE {$linea['boxes']} CAJAS.";
                }
            } else {
                // Bajo cubierta: no lleva contenedores
                $linea['containers_qty'] = null;
                $linea['boxes_per_container'] = null;
            }

            // CONTRATO: el precio sale del contrato vigente esa semana
            if ($linea['negotiation_type'] === 'CONTRATO') {
                $contrato = $this->contratoVigente($linea['customer_id'], $linea['incoterm'], $lunes);
                if (!$contrato) {
                    $errores["lines.$i.negotiation_type"] = "LÍNEA $n: {$cliente->thirdParty?->name} NO TIENE CONTRATO VIGENTE {$linea['incoterm']} PARA LA SEMANA {$data['week']}. REGÍSTRALO EN CONTRATOS DE CLIENTES O MARCA LA LÍNEA COMO SPOT.";
                } else {
                    $linea['contrato'] = $contrato;
                }
            }

            $linea['order_number'] = !empty($linea['order_number']) ? mb_strtoupper(trim($linea['order_number'])) : null;
            $linea['notes'] = !empty($linea['notes']) ? mb_strtoupper(trim($linea['notes'])) : null;
        }
        unset($linea);

        // La suma de las líneas debe cuadrar exacto con el total de la semana
        $suma = array_sum(array_column($data['lines'], 'boxes'));
        if ($suma !== (int) $data['expected_boxes']) {
            $dif = abs($data['expected_boxes'] - $suma);
            $errores['expected_boxes'] = 'LAS LÍNEAS SUMAN ' . number_format($suma, 0, ',', '.') . ' CAJAS Y EL TOTAL DE LA SEMANA ES '
                . number_format($data['expected_boxes'], 0, ',', '.') . ': ' . ($suma < $data['expected_boxes'] ? 'FALTAN ' : 'SOBRAN ')
                . number_format($dif, 0, ',', '.') . ' CAJAS.';
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        return $data;
    }

    // Contrato activo del cliente cuya vigencia cubre la fecha; prefiere el del mismo
    // incoterm sobre uno sin incoterm (que aplica a cualquiera).
    private function contratoVigente(int $clienteId, string $incoterm, Carbon $fecha): ?CustomerContract
    {
        return CustomerContract::where('status', true)
            ->where('customer_id', $clienteId)
            ->whereDate('start_date', '<=', $fecha)
            ->whereDate('end_date', '>=', $fecha)
            ->where(fn ($q) => $q->where('incoterm', $incoterm)->orWhereNull('incoterm'))
            ->orderByRaw('incoterm IS NULL') // primero el del incoterm exacto
            ->first();
    }

    // Crea/actualiza las líneas recibidas y borra las que el usuario quitó.
    // Fase 2/3: cuando las líneas tengan barcos o productores asignados, no se podrán borrar.
    private function guardarLineas(CustomerOrder $pedido, array $lineas): void
    {
        $existentes = $pedido->lines()->get()->keyBy('id');
        $idsRecibidos = [];

        // Fase 2: cajas de cada línea que Logística ya puso en barcos (barcos activos).
        // Una línea embarcada no se puede quitar, cambiar de cliente ni quedar con menos
        // cajas de las embarcadas (primero hay que ajustar la distribución en barcos).
        $embarcado = DB::table('shipment_lines as sl')
            ->join('shipments as s', 's.id', '=', 'sl.shipment_id')
            ->where('s.status', true)
            ->whereIn('sl.customer_order_line_id', $existentes->keys())
            ->groupBy('sl.customer_order_line_id')
            ->selectRaw('sl.customer_order_line_id as linea_id, SUM(sl.box_quantity) as cajas')
            ->pluck('cajas', 'linea_id');
        $recibidas = collect($lineas)->filter(fn ($l) => !empty($l['id']))->keyBy('id');
        $errores = [];
        foreach ($embarcado as $lineaId => $cantidad) {
            $cantidad = (int) $cantidad;
            $cajas = number_format($cantidad, 0, ',', '.'); // solo para el mensaje: 20200 → 20.200
            $actual = $existentes[$lineaId];
            $nueva = $recibidas->get($lineaId);
            $quien = $actual->customer?->thirdParty?->name;
            if (!$nueva) {
                $errores["lines.$lineaId"] = "NO SE PUEDE QUITAR LA LÍNEA DE $quien ({$actual->boxes} CAJAS): YA TIENE $cajas CAJAS EN BARCOS. AJUSTA PRIMERO LA DISTRIBUCIÓN.";
            } elseif ((int) $nueva['boxes'] < $cantidad) {
                $errores["lines.$lineaId"] = "LA LÍNEA DE $quien NO PUEDE QUEDAR CON {$nueva['boxes']} CAJAS: YA TIENE $cajas EN BARCOS.";
            } elseif ((int) $nueva['customer_id'] !== (int) $actual->customer_id) {
                $errores["lines.$lineaId"] = "NO SE PUEDE CAMBIAR EL CLIENTE DE UNA LÍNEA QUE YA ESTÁ EN BARCOS ($quien).";
            }
        }
        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        foreach ($lineas as $linea) {
            $actual = !empty($linea['id']) ? $existentes->get($linea['id']) : null;

            $valores = [
                'customer_id'         => $linea['customer_id'],
                'boxes'               => $linea['boxes'],
                'incoterm'            => $linea['incoterm'],
                'cargo_type'          => $linea['cargo_type'],
                'containers_qty'      => $linea['containers_qty'],
                'boxes_per_container' => $linea['boxes_per_container'],
                'departure_port_id'   => $linea['departure_port_id'],
                'negotiation_type'    => $linea['negotiation_type'],
                'order_number'        => $linea['order_number'],
                'notes'               => $linea['notes'],
            ];

            if ($linea['negotiation_type'] === 'SPOT') {
                $valores['customer_contract_id'] = null;
                $valores['box_price'] = $linea['box_price'] ?? null;
            } else {
                // Precio CONGELADO: si la línea ya era CONTRATO del mismo cliente, incoterm
                // y contrato, conserva el precio guardado aunque el contrato se haya editado.
                $mismoContrato = $actual
                    && $actual->negotiation_type === 'CONTRATO'
                    && $actual->customer_id == $linea['customer_id']
                    && $actual->incoterm === $linea['incoterm']
                    && $actual->customer_contract_id === $linea['contrato']->id;

                $valores['customer_contract_id'] = $linea['contrato']->id;
                $valores['box_price'] = $mismoContrato ? $actual->box_price : $linea['contrato']->box_price;
            }

            if ($actual) {
                $actual->update($valores);
                $idsRecibidos[] = $actual->id;
            } else {
                $nueva = $pedido->lines()->create($valores + ['status' => true]);
                $idsRecibidos[] = $nueva->id;
            }
        }

        $pedido->lines()->whereNotIn('id', $idsRecibidos)->get()->each->delete(); // uno por uno: queda en la bitácora
    }
}
