<?php

// COMPRA DE FRUTA (Comercial) — por BLOQUES, como la hoja semanal "wk"
// Ubicación: app/Http/Controllers/Api/PurchaseAllocationController.php
//
// Un bloque = barco de la semana (con su viaje) + marca + cliente + modalidad (bajo cubierta /
// contenedor) + puerto + META de cajas. Debajo, cada compra:
//   a quién se le compra y se le paga (COMERCIAL o PRODUCTOR directo) + productor real (si se
//   compró a un comercial) + finca + código MAG + ejecutivo + día de corte + puerto de entrega +
//   cajas + precio oficial/real + CONTRATO/SPOT + forma de pago (100 % u 80/20) +
//   transporte de contenedor negociado (se descuenta al liquidar).
// Cada compra es una línea del cupo semanal del vendedor (producer_quotas/lines), que leen el
// Despacho de materiales, las Tarjas y la Liquidación.
//
//   GET /customer-orders/{id}/purchases → pedido, clientes, barcos, bloques con sus compras
//   PUT /customer-orders/{id}/purchases → reemplaza bloques y compras de la semana
// La meta del bloque es referencia (la hoja permite pasarse).
// 29/10/2026: se PERMITE comprar para un cliente más de lo que pidió (la meta es embarcar la mayor
// cantidad de fruta; se completa con SPOT): la pantalla lo marca en ámbar. Cada compra lleva la
// BODEGA donde el vendedor retira cartón y materiales. Las compras se cargan durante la semana por
// varios usuarios: el PUT exige la "version" del GET (si otro guardó antes, responde 409).

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ControlVersion;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Port;
use App\Models\ProducerQuota;
use App\Models\ProducerQuotaLine;
use App\Models\PurchaseBlock;
use App\Models\Shipment;
use App\Models\ThirdParty;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseAllocationController extends Controller
{
    use ControlVersion;

    private const TIPOS_VENDEDOR = ['PRODUCTOR', 'COMERCIALIZADORA'];

    public function show($orderId)
    {
        return response()->json($this->cargar(CustomerOrder::findOrFail($orderId)));
    }

    public function update(Request $request, $orderId)
    {
        $pedido = CustomerOrder::with('lines')->findOrFail($orderId);

        if (!$pedido->status) {
            return response()->json(['message' => 'EL PEDIDO ESTÁ ANULADO: NO SE PUEDE COMPRAR FRUTA PARA ÉL.'], 422);
        }

        $data = $request->validate([
            'blocks'                                 => 'present|array',
            'blocks.*.id'                            => 'nullable|integer',
            'blocks.*.shipment_id'                   => 'required|integer',
            'blocks.*.brand_id'                      => 'required|exists:brands,id',
            'blocks.*.customer_id'                   => 'required|exists:customers,id',
            'blocks.*.cargo_mode'                    => 'required|in:' . implode(',', PurchaseBlock::MODALIDADES),
            'blocks.*.departure_port_id'             => 'nullable|exists:ports,id',
            'blocks.*.target_boxes'                  => 'required|integer|min:0',
            'blocks.*.notes'                         => 'nullable|string|max:255',
            'blocks.*.purchases'                     => 'present|array',
            'blocks.*.purchases.*.id'                => 'nullable|integer',
            'blocks.*.purchases.*.third_party_id'    => 'required|exists:third_parties,id',
            'blocks.*.purchases.*.producer_id'       => 'nullable|exists:third_parties,id',
            'blocks.*.purchases.*.farm_name'         => 'nullable|string|max:120',
            'blocks.*.purchases.*.mag_code'          => 'nullable|string|max:20',
            'blocks.*.purchases.*.executive'         => 'nullable|string|max:60',
            'blocks.*.purchases.*.cut_date'          => 'nullable|date',
            'blocks.*.purchases.*.delivery_port_id'  => 'nullable|exists:ports,id',
            'blocks.*.purchases.*.boxes'             => 'required|integer|min:1',
            'blocks.*.purchases.*.official_price'    => 'nullable|numeric|min:0',
            'blocks.*.purchases.*.real_price'        => 'nullable|numeric|min:0',
            'blocks.*.purchases.*.negotiation_type'  => 'required|in:CONTRATO,SPOT',
            'blocks.*.purchases.*.payment_scheme'    => 'required|in:100,80_20',
            'blocks.*.purchases.*.transport_deduction' => 'nullable|numeric|min:0',
            'blocks.*.purchases.*.notes'             => 'nullable|string|max:255',
            'blocks.*.purchases.*.pickup_warehouse_id' => 'required|exists:warehouses,id',
        ], [
            'blocks.*.purchases.*.pickup_warehouse_id.required' => 'CADA COMPRA DEBE TENER LA BODEGA DONDE RETIRA LOS MATERIALES.',
            'blocks.*.shipment_id.required'                 => 'CADA BLOQUE DEBE TENER BARCO.',
            'blocks.*.brand_id.required'                    => 'CADA BLOQUE DEBE TENER MARCA.',
            'blocks.*.customer_id.required'                 => 'CADA BLOQUE DEBE TENER CLIENTE.',
            'blocks.*.purchases.*.third_party_id.required'  => 'CADA COMPRA DEBE TENER A QUIÉN SE LE COMPRA (COMERCIAL O PRODUCTOR).',
            'blocks.*.purchases.*.boxes.min'                => 'LAS CAJAS DEBEN SER MAYORES A CERO.',
        ]);

        $pedidoPorCliente = $pedido->lines->groupBy('customer_id')->map(fn ($ls) => (int) $ls->sum('boxes'));
        $marcasPorCliente = Customer::with('brands:id')->whereIn('id', $pedidoPorCliente->keys())->get()
            ->mapWithKeys(fn ($c) => [$c->id => $c->brands->pluck('id')->all()]);
        $barcos = Shipment::where('customer_order_id', $pedido->id)->where('status', true)->get()->keyBy('id');
        $compras = collect($data['blocks'])->pluck('purchases')->flatten(1);
        $terceros = ThirdParty::whereIn('id', $compras->pluck('third_party_id')->merge($compras->pluck('producer_id'))->filter()->unique())
            ->get()->keyBy('id');
        $bloquesActuales = PurchaseBlock::where('customer_order_id', $pedido->id)->get()->keyBy('id');
        $actuales = $this->comprasDelPedido($pedido->id)->keyBy('id');
        $bodegasActivas = Warehouse::where('status', true)->pluck('id')->flip();

        $errores = [];
        $recibidas = []; // id compra → [compra, bloque]

        foreach ($data['blocks'] as $b => $bloque) {
            $nb = $b + 1;
            $barco = $barcos->get($bloque['shipment_id']);
            $nombreBloque = 'BLOQUE ' . $nb . ($barco ? " ({$barco->vessel_name})" : '');
            if (!$barco) {
                $errores["blocks.$b.shipment_id"] = "BLOQUE $nb: EL BARCO NO ES DE ESTA SEMANA (ARMA LOS BARCOS EN DISTRIBUCIÓN EN BARCOS).";
            }
            if (!empty($bloque['id']) && !$bloquesActuales->has($bloque['id'])) {
                $errores["blocks.$b"] = "BLOQUE $nb: NO EXISTE O NO ES DE ESTA SEMANA.";
            }
            if (!$pedidoPorCliente->has($bloque['customer_id'])) {
                $errores["blocks.$b.customer_id"] = "$nombreBloque: ESE CLIENTE NO ESTÁ EN EL PEDIDO DE ESTA SEMANA.";
            } elseif (!in_array((int) $bloque['brand_id'], $marcasPorCliente[$bloque['customer_id']] ?? [])) {
                $errores["blocks.$b.brand_id"] = "$nombreBloque: LA MARCA NO PERTENECE A ESE CLIENTE (REVISA LAS MARCAS EN CONFIGURACIÓN → CLIENTES).";
            }
            if (!empty($bloque['departure_port_id']) && Port::find($bloque['departure_port_id'])?->port_type !== 'SALIDA') {
                $errores["blocks.$b.departure_port_id"] = "$nombreBloque: EL PUERTO DEBE SER DE SALIDA (ECUADOR).";
            }

            foreach ($bloque['purchases'] as $i => $c) {
                $n = $i + 1;
                $vendedor = $terceros[$c['third_party_id']];
                if (!in_array($vendedor->type, self::TIPOS_VENDEDOR) || !$vendedor->status) {
                    $errores["blocks.$b.purchases.$i.third_party_id"] = "$nombreBloque, COMPRA $n: {$vendedor->name} NO ES UN COMERCIAL O PRODUCTOR ACTIVO.";
                }
                if (!empty($c['producer_id']) && $terceros[$c['producer_id']]->type !== 'PRODUCTOR') {
                    $errores["blocks.$b.purchases.$i.producer_id"] = "$nombreBloque, COMPRA $n: EL PRODUCTOR REAL DEBE SER DE TIPO PRODUCTOR.";
                }
                if (!empty($c['delivery_port_id']) && Port::find($c['delivery_port_id'])?->port_type !== 'SALIDA') {
                    $errores["blocks.$b.purchases.$i.delivery_port_id"] = "$nombreBloque, COMPRA $n: EL PUERTO DE ENTREGA DEBE SER DE SALIDA (ECUADOR).";
                }
                $actualCompra = !empty($c['id']) ? $actuales->get($c['id']) : null;
                // Una bodega desactivada no recibe compras nuevas (las ya hechas se respetan)
                if (!$bodegasActivas->has($c['pickup_warehouse_id'])
                    && (int) ($actualCompra?->pickup_warehouse_id) !== (int) $c['pickup_warehouse_id']) {
                    $errores["blocks.$b.purchases.$i.pickup_warehouse_id"] = "$nombreBloque, COMPRA $n: LA BODEGA DE RETIRO ESTÁ DESACTIVADA.";
                }
                if (!empty($c['id'])) {
                    if (!$actualCompra) {
                        $errores["blocks.$b.purchases.$i"] = "$nombreBloque, COMPRA $n: NO EXISTE O NO ES DE ESTA SEMANA.";
                    }
                    $recibidas[$c['id']] = [$c, $bloque];
                }
            }
        }

        // Una compra con materiales retirados, tarjas o cupo liquidado no se quita ni cambia de
        // vendedor/marca/cliente; si está liquidada, tampoco cambian cajas, precio ni pago.
        foreach ($actuales as $linea) {
            [$nueva, $bloqueNuevo] = $recibidas[$linea->id] ?? [null, null];
            $cupo = $linea->quota;
            $nombre = $cupo->thirdParty?->name;
            $liquidada = $cupo->status === ProducerQuota::LIQUIDADO;
            $despachada = $cupo->dispatches()->where('status', true)->where('brand_id', $linea->brand_id)
                ->when($linea->pickup_warehouse_id, fn ($q) => $q->where('warehouse_id', $linea->pickup_warehouse_id))
                ->exists();
            $conTarjas = $linea->tallies()->exists();
            if (!$liquidada && !$despachada && !$conTarjas) {
                continue;
            }
            $motivo = $liquidada ? 'SU CUPO YA FUE LIQUIDADO' : ($conTarjas ? 'YA TIENE TARJAS REGISTRADAS' : 'YA RETIRÓ MATERIALES DE ESA MARCA');
            if (!$nueva) {
                $errores["purchases.q{$linea->id}"] = "NO SE PUEDE QUITAR LA COMPRA DE $nombre ({$linea->brand?->name}): $motivo.";
            } elseif ((int) $nueva['third_party_id'] !== (int) $cupo->third_party_id
                || (int) $bloqueNuevo['brand_id'] !== (int) $linea->brand_id
                || (int) $bloqueNuevo['customer_id'] !== (int) $linea->customer_id) {
                $errores["purchases.q{$linea->id}"] = "NO SE PUEDE CAMBIAR EL VENDEDOR, LA MARCA O EL CLIENTE DE LA COMPRA DE $nombre: $motivo.";
            } elseif ($linea->pickup_warehouse_id && (int) $nueva['pickup_warehouse_id'] !== (int) $linea->pickup_warehouse_id) {
                // Los materiales ya salieron de esa bodega: cambiarla descuadra lo retirado
                $errores["purchases.q{$linea->id}"] = "NO SE PUEDE CAMBIAR LA BODEGA DE RETIRO DE LA COMPRA DE $nombre: $motivo.";
            } elseif ($liquidada && ((int) $nueva['boxes'] !== $linea->quantity_cajas
                || (float) ($nueva['real_price'] ?? 0) !== (float) $linea->real_price
                || (float) ($nueva['transport_deduction'] ?? 0) !== (float) $linea->transport_deduction
                || $nueva['negotiation_type'] !== $linea->negotiation_type
                || (string) $nueva['payment_scheme'] !== $linea->payment_scheme)) {
                $errores["purchases.q{$linea->id}"] = "NO SE PUEDE MODIFICAR LA COMPRA DE $nombre: $motivo.";
            }
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        DB::transaction(function () use ($pedido, $data, $actuales, $bloquesActuales, $request) {
            // Bloquea la semana y revisa que nadie haya guardado mientras se editaba
            CustomerOrder::whereKey($pedido->id)->lockForUpdate()->first();
            $this->verificarVersion($request, $this->version($pedido->id));

            $marcas = Brand::whereIn('id', collect($data['blocks'])->pluck('brand_id'))->get()->keyBy('id');
            $idsCompras = [];
            $idsBloques = [];
            $cuposTocados = [];
            $mayus = fn ($v) => isset($v) && trim($v) !== '' ? mb_strtoupper(trim($v)) : null;

            foreach ($data['blocks'] as $bloque) {
                $valoresBloque = [
                    'customer_order_id' => $pedido->id,
                    'shipment_id'       => $bloque['shipment_id'],
                    'brand_id'          => $bloque['brand_id'],
                    'customer_id'       => $bloque['customer_id'],
                    'cargo_mode'        => $bloque['cargo_mode'],
                    'departure_port_id' => $bloque['departure_port_id'] ?? null,
                    'target_boxes'      => $bloque['target_boxes'],
                    'notes'             => $mayus($bloque['notes'] ?? null),
                ];
                $registro = !empty($bloque['id']) ? $bloquesActuales->get($bloque['id']) : null;
                if ($registro) {
                    $registro->update($valoresBloque);
                } else {
                    $registro = PurchaseBlock::create($valoresBloque);
                }
                $idsBloques[] = $registro->id;

                foreach ($bloque['purchases'] as $c) {
                    // Un cupo por vendedor + semana del pedido (UNIQUE en la base)
                    $cupo = ProducerQuota::firstOrNew([
                        'third_party_id' => $c['third_party_id'],
                        'week_number'    => $pedido->week,
                        'year'           => $pedido->year,
                    ]);
                    if (!$cupo->exists) {
                        $cupo->fill(['status' => ProducerQuota::ASIGNADO, 'total_cupo' => 0, 'created_by' => $request->user()?->id]);
                    }
                    $cupo->customer_order_id = $pedido->id;
                    $cupo->save();
                    $cuposTocados[$cupo->id] = $cupo;

                    $valores = [
                        'producer_quota_id'   => $cupo->id,
                        'purchase_block_id'   => $registro->id,
                        'customer_id'         => $bloque['customer_id'],
                        'brand_id'            => $bloque['brand_id'],
                        'variety'             => $marcas[$bloque['brand_id']]->code, // lo que lee el despacho
                        'quantity_cajas'      => $c['boxes'],
                        'official_price'      => $c['official_price'] ?? null,
                        'real_price'          => $c['real_price'] ?? null,
                        'negotiation_type'    => $c['negotiation_type'],
                        'payment_scheme'      => (string) $c['payment_scheme'],
                        // Productor real solo si es distinto de a quién se le compra
                        'producer_id'         => !empty($c['producer_id']) && (int) $c['producer_id'] !== (int) $c['third_party_id'] ? $c['producer_id'] : null,
                        'farm_name'           => $mayus($c['farm_name'] ?? null),
                        'mag_code'            => $mayus($c['mag_code'] ?? null),
                        'executive'           => $mayus($c['executive'] ?? null),
                        'cut_date'            => $c['cut_date'] ?? null,
                        'delivery_port_id'    => $c['delivery_port_id'] ?? null,
                        'transport_deduction' => $c['transport_deduction'] ?? 0,
                        'notes'               => $mayus($c['notes'] ?? null),
                        'pickup_warehouse_id' => $c['pickup_warehouse_id'],
                    ];

                    $actual = !empty($c['id']) ? $actuales->get($c['id']) : null;
                    if ($actual) {
                        $cuposTocados[$actual->producer_quota_id] ??= $actual->quota;
                        $actual->update($valores);
                        $idsCompras[] = $actual->id;
                    } else {
                        $idsCompras[] = ProducerQuotaLine::create($valores)->id;
                    }
                }
            }

            // Compras y bloques quitados por Comercial
            foreach ($actuales->except($idsCompras) as $quitada) {
                $cuposTocados[$quitada->producer_quota_id] ??= $quitada->quota;
                $quitada->delete();
            }
            PurchaseBlock::where('customer_order_id', $pedido->id)->whereNotIn('id', $idsBloques)->delete();

            // Total de cada cupo = suma de sus líneas. Un cupo sin compras ni despachos se elimina.
            foreach ($cuposTocados as $cupo) {
                $total = $cupo->lines()->sum('quantity_cajas');
                if ($total == 0 && !$cupo->dispatches()->exists()) {
                    $cupo->delete();
                } else {
                    $cupo->update(['total_cupo' => $total]);
                }
            }
        });

        return response()->json($this->cargar($pedido->fresh()));
    }

    // Huella de bloques + compras de la semana (control de cambios simultáneos)
    private function version(int $pedidoId): string
    {
        $bloques = DB::table('purchase_blocks')->where('customer_order_id', $pedidoId);
        // MAX/COUNT sobre l.updated_at (la consulta tiene join, no sirve huella())
        $fila = DB::table('producer_quota_lines as l')
            ->join('producer_quotas as q', 'q.id', '=', 'l.producer_quota_id')
            ->where('q.customer_order_id', $pedidoId)
            ->whereNotNull('l.customer_id')
            ->selectRaw('COUNT(*) AS n, MAX(l.updated_at) AS u')
            ->first();

        return md5($this->huella([$bloques]) . '|' . $fila->n . '@' . $fila->u);
    }

    // Compras (líneas de cupo) de Comercial para este pedido
    private function comprasDelPedido(int $pedidoId)
    {
        return ProducerQuotaLine::with(['quota.thirdParty:id,name,type', 'brand:id,name,code', 'producer:id,name'])
            ->whereHas('quota', fn ($q) => $q->where('customer_order_id', $pedidoId))
            ->whereNotNull('customer_id')
            ->orderBy('id')
            ->get();
    }

    private function cargar(CustomerOrder $pedido): array
    {
        $pedido->load(['lines:id,customer_order_id,customer_id,boxes']);

        $enBarcos = DB::table('shipment_lines as sl')
            ->join('shipments as s', 's.id', '=', 'sl.shipment_id')
            ->where('s.customer_order_id', $pedido->id)
            ->where('s.status', true)
            ->groupBy('sl.customer_id')
            ->selectRaw('sl.customer_id, SUM(sl.box_quantity) as cajas')
            ->pluck('cajas', 'sl.customer_id');

        $clientes = Customer::with(['thirdParty:id,name', 'brands:id,name,code,status'])
            ->whereIn('id', $pedido->lines->pluck('customer_id')->unique())
            ->get()
            ->map(fn ($c) => [
                'id'        => $c->id,
                'name'      => $c->thirdParty?->name,
                'brands'    => $c->brands->values(),
                'pedido'    => (int) $pedido->lines->where('customer_id', $c->id)->sum('boxes'),
                'en_barcos' => (int) ($enBarcos[$c->id] ?? 0),
            ])
            ->values();

        // Una sola consulta para saber qué compras tienen tarjas y qué marcas ya retiraron material
        $lineas = $this->comprasDelPedido($pedido->id);
        $conTarjas = DB::table('tally_sheets')->whereIn('producer_quota_line_id', $lineas->pluck('id'))
            ->distinct()->pluck('producer_quota_line_id')->flip();
        $despachos = DB::table('inventory_movements')->where('status', true)
            ->whereIn('producer_quota_id', $lineas->pluck('producer_quota_id')->unique())
            ->select('producer_quota_id', 'brand_id', 'warehouse_id')->distinct()->get();
        // Retiró materiales de esa marca en la bodega de la compra (sin bodega: en cualquiera)
        $despachada = fn ($l) => $despachos->contains(fn ($d) => (int) $d->producer_quota_id === (int) $l->producer_quota_id
            && (int) $d->brand_id === (int) $l->brand_id
            && (!$l->pickup_warehouse_id || (int) $d->warehouse_id === (int) $l->pickup_warehouse_id));

        // Última bodega de retiro usada por cada vendedor (se propone en la compra nueva)
        $ultimaBodega = DB::table('producer_quota_lines as l')
            ->join('producer_quotas as q', 'q.id', '=', 'l.producer_quota_id')
            ->whereNotNull('l.pickup_warehouse_id')
            ->orderByDesc('l.id')
            ->get(['q.third_party_id', 'l.pickup_warehouse_id'])
            ->unique('third_party_id')
            ->pluck('pickup_warehouse_id', 'third_party_id');

        $compras = $lineas->map(function ($l) use ($conTarjas, $despachada) {
            $cupo = $l->quota;
            return [
                'id'                  => $l->id,
                'purchase_block_id'   => $l->purchase_block_id,
                'third_party_id'      => $cupo->third_party_id,
                'third_party'         => $cupo->thirdParty,
                'producer_id'         => $l->producer_id,
                'producer'            => $l->producer,
                'farm_name'           => $l->farm_name,
                'mag_code'            => $l->mag_code,
                'executive'           => $l->executive,
                'cut_date'            => $l->cut_date?->format('Y-m-d'),
                'delivery_port_id'    => $l->delivery_port_id,
                'boxes'               => (int) $l->quantity_cajas,
                'official_price'      => $l->official_price,
                'real_price'          => $l->real_price,
                'negotiation_type'    => $l->negotiation_type,
                'payment_scheme'      => $l->payment_scheme,
                'transport_deduction' => $l->transport_deduction,
                'notes'               => $l->notes,
                'pickup_warehouse_id' => $l->pickup_warehouse_id,
                'quota_status'        => $cupo->status,
                'con_tarjas'          => $conTarjas->has($l->id),
                'despachada'          => $despachada($l),
            ];
        })->groupBy('purchase_block_id');

        $bloques = PurchaseBlock::where('customer_order_id', $pedido->id)->orderBy('id')->get()
            ->map(fn ($b) => $b->toArray() + ['purchases' => ($compras[$b->id] ?? collect())->values()]);

        return [
            'order'      => $pedido->only(['id', 'week', 'year', 'expected_boxes', 'status']),
            'customers'  => $clientes,
            'shipments'  => Shipment::with(['vessel:id,name', 'departurePort:id,name'])
                ->where('customer_order_id', $pedido->id)->where('status', true)->orderBy('departure_date')->orderBy('id')
                ->get(['id', 'code', 'vessel_id', 'vessel_name', 'voyage', 'departure_port_id', 'departure_date']),
            'blocks'     => $bloques,
            // Ejecutivos ya usados (para sugerir al escribir)
            'executives' => ProducerQuotaLine::whereNotNull('executive')->distinct()->orderBy('executive')->pluck('executive'),
            'last_pickup' => $ultimaBodega,
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name', 'zone', 'status']),
            'version'    => $this->version($pedido->id),
        ];
    }
}
