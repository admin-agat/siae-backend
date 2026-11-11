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
// 10/11/2026: SEMANA DE COMPRA por línea. Una nave con cupo en el pedido de una semana a veces se queda a la
// siguiente y se le sigue comprando: lo que llega al puerto hasta el domingo de esa semana es compra de esa
// semana (se liquida y cuenta en el cupo del contrato ahí). La compra va al cupo del vendedor de SU semana de
// compra (la del pedido o la siguiente); las compras del pedido se leen por su bloque, no por el cupo.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ControlVersion;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Farm;
use App\Models\OfficialBoxPrice;
use App\Models\Port;
use App\Models\ProducerQuota;
use App\Models\ProducerQuotaLine;
use App\Models\PurchaseBlock;
use App\Models\PurchaseExecutive;
use App\Models\Shipment;
use App\Models\ThirdParty;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            'blocks.*.presentation'                  => 'required|in:' . implode(',', Brand::PRESENTACIONES),
            'blocks.*.customer_id'                   => 'required|exists:customers,id',
            'blocks.*.cargo_mode'                    => 'required|in:' . implode(',', PurchaseBlock::MODALIDADES),
            'blocks.*.departure_port_id'             => 'nullable|exists:ports,id',
            'blocks.*.target_boxes'                  => 'required|integer|min:0',
            'blocks.*.notes'                         => 'nullable|string|max:255',
            'blocks.*.purchases'                     => 'present|array',
            'blocks.*.purchases.*.id'                => 'nullable|integer',
            'blocks.*.purchases.*.third_party_id'    => 'required|exists:third_parties,id',
            'blocks.*.purchases.*.producer_id'       => 'nullable|exists:third_parties,id',
            // 04/11/2026: la finca se elige del catálogo; su nombre y código MAG los pone el backend
            'blocks.*.purchases.*.farm_id'           => 'nullable|integer',
            'blocks.*.purchases.*.executive'         => 'nullable|string|max:255', // 11/11/2026: uno o varios "A / B"
            'blocks.*.purchases.*.cut_date'          => 'nullable|date',
            'blocks.*.purchases.*.purchase_week'     => 'nullable|integer|min:1|max:53',
            'blocks.*.purchases.*.delivery_port_id'  => 'nullable|exists:ports,id',
            'blocks.*.purchases.*.boxes'             => 'required|integer|min:1',
            'blocks.*.purchases.*.official_price'    => 'nullable|numeric|min:0',
            'blocks.*.purchases.*.real_price'        => 'nullable|numeric|min:0',
            'blocks.*.purchases.*.negotiation_type'  => 'required|in:CONTRATO,SPOT',
            'blocks.*.purchases.*.payment_scheme'    => 'required|in:100,80_20',
            // 06/11/2026: qué contrato del vendedor es (CONTRATO 1, 2…) y qué parte (1 = 0, 1.1 = 1…)
            'blocks.*.purchases.*.contract_id'       => 'nullable|integer',
            'blocks.*.purchases.*.contract_part'     => 'nullable|integer|min:0|max:9',
            'blocks.*.purchases.*.transport_deduction' => 'nullable|numeric|min:0',
            // 11/11/2026: $/caja que se le reconoce cuando el productor pone el transporte (null = no se reconoce)
            'blocks.*.purchases.*.transport_recognized_rate' => 'nullable|numeric|min:0|max:5',
            'blocks.*.purchases.*.notes'             => 'nullable|string|max:255',
            // 10/11/2026: opcional (las compras históricas se cargan sin bodega); la pantalla la exige en las nuevas
            'blocks.*.purchases.*.pickup_warehouse_id' => 'nullable|exists:warehouses,id',
        ], [
            'blocks.*.shipment_id.required'                 => 'CADA BLOQUE DEBE TENER BARCO.',
            'blocks.*.brand_id.required'                    => 'CADA BLOQUE DEBE TENER MARCA.',
            'blocks.*.presentation.required'                => 'CADA BLOQUE DEBE TENER PRESENTACIÓN (CONVENCIONAL O PACKED).',
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
        $comercialesActivos = PurchaseExecutive::where('status', true)->pluck('name')->flip(); // 11/11/2026
        $fincas = Farm::whereIn('id', $compras->pluck('farm_id')->filter()->unique())->get()->keyBy('id');
        $contratos = Contract::whereIn('id', $compras->pluck('contract_id')->filter()->unique())->get()->keyBy('id');
        $conContratos = Contract::where('status', true)->whereIn('third_party_id', $compras->pluck('third_party_id')->unique())
            ->get()->groupBy('third_party_id'); // 11/11/2026: con sus fechas, para ver cuáles valen en la semana

        // Semanas de compra posibles: la del pedido o la siguiente (nave que se queda una semana más)
        $semanas = $this->semanasDeCompra($pedido);
        $semanaDe = fn ($c) => $semanas[(int) ($c['purchase_week'] ?? $pedido->week)] ?? null;
        $cuposLiquidados = ProducerQuota::where('status', ProducerQuota::LIQUIDADO)
            ->whereIn('third_party_id', $compras->pluck('third_party_id')->unique())
            ->where(function ($q) use ($semanas) {
                foreach ($semanas as $w) {
                    $q->orWhere(fn ($x) => $x->where('year', $w['year'])->where('week_number', $w['week']));
                }
            })
            ->get()->map(fn ($q) => "{$q->third_party_id}|{$q->year}|{$q->week_number}")->flip();

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
                $errores["blocks.$b.brand_id"] = "$nombreBloque: LA MARCA NO PERTENECE A ESE CLIENTE (REVISA LAS MARCAS EN CONFIGURACIÓN → TERCEROS).";
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
                // 02/11/2026: la fruta PACKED se le compra al productor siempre SPOT (aunque la venta a Rusia
                // sea por contrato); el precio se negocia con cada productor
                if ($bloque['presentation'] === 'PACKED' && $c['negotiation_type'] !== 'SPOT') {
                    $errores["blocks.$b.purchases.$i.negotiation_type"] = "$nombreBloque, COMPRA $n: LA FRUTA PACKED SE COMPRA SIEMPRE SPOT.";
                }
                $actualCompra = !empty($c['id']) ? $actuales->get($c['id']) : null;
                $semana = $semanaDe($c);
                $mismoCupo = $semana && $actualCompra && (int) $actualCompra->quota->third_party_id === (int) $c['third_party_id']
                    && (int) $actualCompra->quota->week_number === $semana['week'] && (int) $actualCompra->quota->year === $semana['year'];
                if (!$semana) {
                    $otra = end($semanas)['week'];
                    $errores["blocks.$b.purchases.$i.purchase_week"] = "$nombreBloque, COMPRA $n: LA SEMANA DE COMPRA ES LA DEL PEDIDO (WK{$pedido->week}) O LA SIGUIENTE (WK$otra).";
                } elseif (!$mismoCupo && $cuposLiquidados->has("{$c['third_party_id']}|{$semana['year']}|{$semana['week']}")) {
                    $errores["blocks.$b.purchases.$i.purchase_week"] = "$nombreBloque, COMPRA $n: {$vendedor->name} YA TIENE LIQUIDADA LA WK{$semana['week']}: NO SE LE PUEDEN AGREGAR COMPRAS EN ESA SEMANA.";
                }
                // 06/11/2026: la compra de CONTRATO dice qué contrato del vendedor es (Base Productores).
                // Si el vendedor todavía no tiene contratos registrados se deja pasar (Liquidaciones los carga).
                if ($c['negotiation_type'] === 'CONTRATO') {
                    $contrato = !empty($c['contract_id']) ? $contratos->get($c['contract_id']) : null;
                    if (!empty($c['contract_id']) && (!$contrato || (int) $contrato->third_party_id !== (int) $c['third_party_id'])) {
                        $errores["blocks.$b.purchases.$i.contract_id"] = "$nombreBloque, COMPRA $n: ESE CONTRATO NO ES DE {$vendedor->name}.";
                    } elseif ($contrato && !$contrato->status && (int) ($actualCompra?->contract_id) !== (int) $contrato->id) {
                        $errores["blocks.$b.purchases.$i.contract_id"] = "$nombreBloque, COMPRA $n: EL CONTRATO {$contrato->number} DE {$vendedor->name} ESTÁ DESACTIVADO.";
                    } elseif ($contrato && $semana && !$contrato->vigenteEnSemana($semana['year'], $semana['week'])
                        && ((int) ($actualCompra?->contract_id) !== (int) $contrato->id || !$mismoCupo)) {
                        // 11/11/2026: solo contratos vigentes en la semana de compra (las compras ya hechas se respetan)
                        $hasta = $contrato->end_date ? ' (VENCE ' . $contrato->end_date->format('d/m/Y') . ')' : '';
                        $errores["blocks.$b.purchases.$i.contract_id"] = "$nombreBloque, COMPRA $n: EL CONTRATO {$contrato->number} DE {$vendedor->name} NO ESTÁ VIGENTE EN LA WK{$semana['week']}$hasta.";
                    } elseif (!$contrato && $semana && $conContratos->get($c['third_party_id'], collect())->contains(fn ($k) => $k->vigenteEnSemana($semana['year'], $semana['week']))) {
                        $errores["blocks.$b.purchases.$i.contract_id"] = "$nombreBloque, COMPRA $n: ELIGE QUÉ CONTRATO DE {$vendedor->name} ES.";
                    }
                }
                // 11/11/2026: los comerciales salen de la lista de ADMIN; los que ya tenía la compra se respetan
                $antes = PurchaseExecutive::nombres($actualCompra?->executive);
                foreach (PurchaseExecutive::nombres(mb_strtoupper($c['executive'] ?? '')) as $comercial) {
                    if (!$comercialesActivos->has($comercial) && !in_array($comercial, $antes, true)) {
                        $errores["blocks.$b.purchases.$i.executive"] = "$nombreBloque, COMPRA $n: $comercial NO ESTÁ EN LA LISTA DE COMERCIALES.";
                    }
                }
                // Una bodega desactivada no recibe compras nuevas (las ya hechas se respetan)
                if (!empty($c['pickup_warehouse_id']) && !$bodegasActivas->has($c['pickup_warehouse_id'])
                    && (int) ($actualCompra?->pickup_warehouse_id) !== (int) $c['pickup_warehouse_id']) {
                    $errores["blocks.$b.purchases.$i.pickup_warehouse_id"] = "$nombreBloque, COMPRA $n: LA BODEGA DE RETIRO ESTÁ DESACTIVADA.";
                }
                // 04/11/2026: la finca es del catálogo y del productor real (en compra directa, del vendedor).
                // Obligatoria en las compras nuevas; las de antes sin finca se pueden seguir guardando así.
                $duenoId = !empty($c['producer_id']) ? (int) $c['producer_id'] : (int) $c['third_party_id'];
                $finca = !empty($c['farm_id']) ? $fincas->get($c['farm_id']) : null;
                if (!empty($c['farm_id'])) {
                    if (!$finca) {
                        $errores["blocks.$b.purchases.$i.farm_id"] = "$nombreBloque, COMPRA $n: LA FINCA NO EXISTE.";
                    } elseif ((int) $finca->third_party_id !== $duenoId) {
                        $dueno = $terceros[$duenoId]->name ?? '';
                        $errores["blocks.$b.purchases.$i.farm_id"] = "$nombreBloque, COMPRA $n: LA FINCA {$finca->name} NO ES DE $dueno.";
                    } elseif (!$finca->status && (int) ($actualCompra?->farm_id) !== (int) $finca->id) {
                        $errores["blocks.$b.purchases.$i.farm_id"] = "$nombreBloque, COMPRA $n: LA FINCA {$finca->name} ESTÁ DESACTIVADA.";
                    }
                } elseif (!$actualCompra || $actualCompra->farm_id) {
                    $errores["blocks.$b.purchases.$i.farm_id"] = $vendedor->type === 'COMERCIALIZADORA' && empty($c['producer_id'])
                        ? "$nombreBloque, COMPRA $n: ELIGE EL PRODUCTOR REAL Y SU FINCA (SE LE COMPRA A UN COMERCIAL)."
                        : "$nombreBloque, COMPRA $n: FALTA LA FINCA.";
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
            $despachada = $this->yaRetiro($linea);
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
            } elseif ((int) ($nueva['purchase_week'] ?? $pedido->week) !== (int) $cupo->week_number) {
                // Cambiar la semana mueve la compra a otro cupo: descuadra lo retirado, las tarjas o la liquidación
                $errores["purchases.q{$linea->id}"] = "NO SE PUEDE CAMBIAR LA SEMANA DE COMPRA DE $nombre: $motivo.";
            } elseif ($linea->pickup_warehouse_id && (int) ($nueva['pickup_warehouse_id'] ?? 0) !== (int) $linea->pickup_warehouse_id) {
                // Los materiales ya salieron de esa bodega: cambiarla descuadra lo retirado
                $errores["purchases.q{$linea->id}"] = "NO SE PUEDE CAMBIAR LA BODEGA DE RETIRO DE LA COMPRA DE $nombre: $motivo.";
            } elseif ($liquidada && ((int) $nueva['boxes'] !== $linea->quantity_cajas
                || (float) ($nueva['real_price'] ?? 0) !== (float) $linea->real_price
                || (float) ($nueva['transport_deduction'] ?? 0) !== (float) $linea->transport_deduction
                || (float) ($nueva['transport_recognized_rate'] ?? 0) !== (float) $linea->transport_recognized_rate
                || (int) ($nueva['farm_id'] ?? 0) !== (int) $linea->farm_id
                || $nueva['negotiation_type'] !== $linea->negotiation_type
                || (string) $nueva['payment_scheme'] !== $linea->payment_scheme
                || ($nueva['negotiation_type'] === 'CONTRATO' ? (int) ($nueva['contract_id'] ?? 0) : 0) !== (int) $linea->contract_id
                || ($nueva['negotiation_type'] === 'CONTRATO' ? (int) ($nueva['contract_part'] ?? 0) : 0) !== (int) $linea->contract_part)) {
                $errores["purchases.q{$linea->id}"] = "NO SE PUEDE MODIFICAR LA COMPRA DE $nombre: $motivo.";
            }
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        DB::transaction(function () use ($pedido, $data, $actuales, $bloquesActuales, $fincas, $request, $semanaDe) {
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
                    'presentation'      => $bloque['presentation'],
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
                    // Un cupo por vendedor + semana de compra (UNIQUE en la base)
                    $semana = $semanaDe($c);
                    $cupo = ProducerQuota::firstOrNew([
                        'third_party_id' => $c['third_party_id'],
                        'week_number'    => $semana['week'],
                        'year'           => $semana['year'],
                    ]);
                    if (!$cupo->exists) {
                        $cupo->fill(['status' => ProducerQuota::ASIGNADO, 'total_cupo' => 0, 'created_by' => $request->user()?->id]);
                    }
                    // El cupo de la semana siguiente puede tener compras de dos pedidos: queda el primero
                    $cupo->customer_order_id ??= $pedido->id;
                    $cupo->save();
                    $cuposTocados[$cupo->id] = $cupo;

                    $actual = !empty($c['id']) ? $actuales->get($c['id']) : null;
                    // Nombre y MAG = copia de la finca al comprar; si la finca no cambió se conserva la copia
                    // (el historial no cambia si después editan la finca). Sin finca (compras de antes): lo que tenía.
                    $finca = !empty($c['farm_id']) ? $fincas->get($c['farm_id']) : null;
                    if ($finca && (int) $actual?->farm_id !== (int) $finca->id) {
                        $copiaFinca = [$mayus($finca->name), $mayus($finca->magap_code)];
                    } else {
                        $copiaFinca = [$actual?->farm_name, $actual?->mag_code];
                    }

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
                        'farm_id'             => $finca?->id,
                        'farm_name'           => $copiaFinca[0],
                        'mag_code'            => $copiaFinca[1],
                        // 11/11/2026: "MARIA ELENA / JORGE ZERNA" (sin repetidos)
                        'executive'           => implode(PurchaseExecutive::SEPARADOR, array_unique(PurchaseExecutive::nombres(mb_strtoupper($c['executive'] ?? '')))) ?: null,
                        'cut_date'            => $c['cut_date'] ?? null,
                        'delivery_port_id'    => $c['delivery_port_id'] ?? null,
                        'transport_deduction' => $c['transport_deduction'] ?? 0,
                        'transport_recognized_rate' => !empty($c['transport_recognized_rate']) ? $c['transport_recognized_rate'] : null,
                        'notes'               => $mayus($c['notes'] ?? null),
                        'pickup_warehouse_id' => $c['pickup_warehouse_id'] ?? null,
                        // Solo las compras de CONTRATO llevan contrato y parte
                        'contract_id'         => $c['negotiation_type'] === 'CONTRATO' ? ($c['contract_id'] ?? null) : null,
                        'contract_part'       => $c['negotiation_type'] === 'CONTRATO' ? (int) ($c['contract_part'] ?? 0) : 0,
                    ];

                    // 10/11/2026: ya enviada a bodega y cambia lo que la bodega entrega → aviso "CAMBIÓ"
                    if ($actual?->sent_to_warehouse_at && (
                        (int) $c['boxes'] !== (int) $actual->quantity_cajas
                        || (int) $bloque['brand_id'] !== (int) $actual->brand_id
                        || (int) ($c['pickup_warehouse_id'] ?? 0) !== (int) $actual->pickup_warehouse_id
                        || (int) $c['third_party_id'] !== (int) $actual->quota->third_party_id
                        || (int) $cupo->id !== (int) $actual->producer_quota_id)) {
                        $valores['changed_after_sent_at'] = now();
                    }

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
            PurchaseBlock::where('customer_order_id', $pedido->id)->whereNotIn('id', $idsBloques)->get()->each->delete(); // uno por uno: queda en la bitácora

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

    /**
     * POST /customer-orders/{id}/purchases/send — 10/11/2026: Comercial envía compras a bodega
     * Body: { purchase_ids: [..] } (una compra o todas las del bloque). Las que ya estaban enviadas no
     * se vuelven a enviar (conservan su fecha); las de cupo liquidado se saltan.
     * No cambia la "version": enviar no choca con otro usuario que esté editando la semana.
     */
    public function enviarABodega(Request $request, $orderId)
    {
        $pedido = CustomerOrder::findOrFail($orderId);
        if (!$pedido->status) {
            return response()->json(['message' => 'EL PEDIDO ESTÁ ANULADO.'], 422);
        }
        $data = $request->validate([
            'purchase_ids'   => 'required|array|min:1',
            'purchase_ids.*' => 'integer|distinct',
        ]);

        [$enviadas, $yaEnviadas] = DB::transaction(function () use ($pedido, $data, $request) {
            // Bloquea la semana: dos clics seguidos (o dos usuarios) no envían dos veces
            CustomerOrder::whereKey($pedido->id)->lockForUpdate()->first();
            $lineas = $this->comprasDelPedido($pedido->id)->whereIn('id', $data['purchase_ids']);
            if ($lineas->count() !== count($data['purchase_ids'])) {
                throw ValidationException::withMessages(['purchase_ids' => 'ALGUNA COMPRA NO EXISTE O NO ES DE ESTA SEMANA: RECARGA LA PANTALLA.']);
            }
            $enviadas = 0;
            $yaEnviadas = 0;
            foreach ($lineas as $linea) {
                if ($linea->sent_to_warehouse_at) {
                    $yaEnviadas++;
                } elseif ($linea->quota->status !== ProducerQuota::LIQUIDADO) {
                    $linea->update([ // uno por uno: queda en la bitácora
                        'sent_to_warehouse_at'  => now(),
                        'sent_to_warehouse_by'  => $request->user()?->id,
                        'changed_after_sent_at' => null,
                    ]);
                    $enviadas++;
                }
            }

            return [$enviadas, $yaEnviadas];
        });

        return response()->json($this->cargar($pedido->fresh()) + ['enviadas' => $enviadas, 'ya_enviadas' => $yaEnviadas]);
    }

    /**
     * PATCH /customer-orders/{id}/purchases/{lineId}/unsend — quitar el envío (enviada por error).
     * Solo mientras la bodega no haya entregado materiales de esa marca en esa bodega.
     */
    public function quitarEnvio($orderId, $lineId)
    {
        $pedido = CustomerOrder::findOrFail($orderId);

        DB::transaction(function () use ($pedido, $lineId) {
            CustomerOrder::whereKey($pedido->id)->lockForUpdate()->first();
            $linea = $this->comprasDelPedido($pedido->id)->firstWhere('id', (int) $lineId);
            $error = match (true) {
                !$linea                          => 'LA COMPRA NO EXISTE O NO ES DE ESTA SEMANA: RECARGA LA PANTALLA.',
                !$linea->sent_to_warehouse_at    => 'LA COMPRA NO ESTÁ ENVIADA A BODEGA.',
                $this->yaRetiro($linea)          => 'LA BODEGA YA ENTREGÓ MATERIALES DE ESTA COMPRA: NO SE PUEDE QUITAR EL ENVÍO.',
                default                          => null,
            };
            if ($error) {
                throw ValidationException::withMessages(['purchase' => $error]);
            }
            $linea->update(['sent_to_warehouse_at' => null, 'sent_to_warehouse_by' => null, 'changed_after_sent_at' => null]);
        });

        return response()->json($this->cargar($pedido->fresh()));
    }

    // ¿Ya retiró materiales de la marca de esta compra en su bodega de retiro (sin bodega: en cualquiera)?
    private function yaRetiro(ProducerQuotaLine $linea): bool
    {
        return $linea->quota->dispatches()->where('status', true)->where('brand_id', $linea->brand_id)
            ->when($linea->pickup_warehouse_id, fn ($q) => $q->where('warehouse_id', $linea->pickup_warehouse_id))
            ->exists();
    }

    // Columnas de las compras que NO entran en la huella: enviar a bodega no es editar la semana
    private const FUERA_DE_HUELLA = ['updated_at', 'sent_to_warehouse_at', 'sent_to_warehouse_by'];

    // Huella de bloques + compras de la semana (control de cambios simultáneos)
    private function version(int $pedidoId): string
    {
        $bloques = DB::table('purchase_blocks')->where('customer_order_id', $pedidoId);
        // Solo las columnas de las líneas: la huella va sobre el contenido de cada fila
        $columnas = collect(Schema::getColumnListing('producer_quota_lines'))
            ->diff(self::FUERA_DE_HUELLA)->map(fn ($c) => "l.$c")->values()->all();
        $compras = DB::table('producer_quota_lines as l')
            ->join('purchase_blocks as b', 'b.id', '=', 'l.purchase_block_id')
            ->where('b.customer_order_id', $pedidoId)
            ->whereNotNull('l.customer_id')
            ->select($columnas);

        return $this->huella([$bloques, $compras]);
    }

    // Compras (líneas de cupo) de Comercial para este pedido, por su bloque (10/11/2026: el cupo puede ser
    // de la semana siguiente y tener compras de dos pedidos)
    private function comprasDelPedido(int $pedidoId)
    {
        return ProducerQuotaLine::with(['quota.thirdParty:id,name,type', 'brand:id,name,code', 'producer:id,name', 'sentBy:id,name'])
            ->whereHas('block', fn ($q) => $q->where('customer_order_id', $pedidoId))
            ->whereNotNull('customer_id')
            ->orderBy('id')
            ->get();
    }

    /** Semanas de compra posibles del pedido: la suya y la siguiente (con cambio de año), por número de semana. */
    private function semanasDeCompra(CustomerOrder $pedido): array
    {
        $siguiente = Carbon::now()->setISODate((int) $pedido->year, (int) $pedido->week)->startOfWeek()->addWeek();
        return [
            (int) $pedido->week   => ['year' => (int) $pedido->year, 'week' => (int) $pedido->week],
            $siguiente->isoWeek() => ['year' => $siguiente->isoWeekYear(), 'week' => $siguiente->isoWeek()],
        ];
    }

    private function cargar(CustomerOrder $pedido): array
    {
        $pedido->load(['lines:id,customer_order_id,customer_id,brand_id,presentation,boxes']);

        $enBarcos = DB::table('shipment_lines as sl')
            ->join('shipments as s', 's.id', '=', 'sl.shipment_id')
            ->where('s.customer_order_id', $pedido->id)
            ->where('s.status', true)
            ->groupBy('sl.customer_id')
            ->selectRaw('sl.customer_id, SUM(sl.box_quantity) as cajas')
            ->pluck('cajas', 'sl.customer_id');

        $clientes = Customer::with(['thirdParty:id,name', 'brands:id,name,code,status,box_type_id'])
            ->whereIn('id', $pedido->lines->pluck('customer_id')->unique())
            ->get()
            ->map(fn ($c) => [
                'id'        => $c->id,
                'name'      => $c->thirdParty?->name,
                'brands'    => $c->brands->values(),
                'pedido'    => (int) $pedido->lines->where('customer_id', $c->id)->sum('boxes'),
                // 02/11/2026: lo que pidió Rusia por marca + presentación (meta de cada bloque)
                'pedido_detalle' => $pedido->lines->where('customer_id', $c->id)
                    ->groupBy(fn ($l) => $l->brand_id . '|' . $l->presentation)
                    ->map(fn ($ls) => ['brand_id' => $ls->first()->brand_id, 'presentation' => $ls->first()->presentation, 'cajas' => (int) $ls->sum('boxes')])
                    ->values(),
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
                'farm_id'             => $l->farm_id,
                'farm_name'           => $l->farm_name,
                'mag_code'            => $l->mag_code,
                'executive'           => $l->executive,
                'cut_date'            => $l->cut_date?->format('Y-m-d'),
                'purchase_week'       => (int) $cupo->week_number,
                'delivery_port_id'    => $l->delivery_port_id,
                'boxes'               => (int) $l->quantity_cajas,
                'official_price'      => $l->official_price,
                'real_price'          => $l->real_price,
                'negotiation_type'    => $l->negotiation_type,
                'payment_scheme'      => $l->payment_scheme,
                'transport_deduction' => $l->transport_deduction,
                'transport_recognized_rate' => $l->transport_recognized_rate,
                'notes'               => $l->notes,
                'pickup_warehouse_id' => $l->pickup_warehouse_id,
                'contract_id'         => $l->contract_id,
                'contract_part'       => (int) $l->contract_part,
                'quota_status'        => $cupo->status,
                'con_tarjas'          => $conTarjas->has($l->id),
                'despachada'          => $despachada($l),
                // 10/11/2026: enviada a bodega para despachar materiales
                'sent_at'             => $l->sent_to_warehouse_at?->toIso8601String(),
                'sent_by'             => $l->sentBy?->name,
                'changed_after_sent_at' => $l->changed_after_sent_at?->toIso8601String(),
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
            // 11/11/2026: comerciales activos de la lista de ADMIN (se eligen uno o varios por compra)
            'executives' => PurchaseExecutive::where('status', true)->orderBy('name')->pluck('name'),
            'last_pickup' => $ultimaBodega,
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name', 'zone', 'status']),
            // Precios oficiales MAG activos: la compra propone el del tipo de caja de la marca (30/10/2026)
            'official_prices' => OfficialBoxPrice::with('boxType:id,name')->where('status', true)
                ->orderByDesc('valid_from')->get(['id', 'box_type_id', 'valid_from', 'valid_to', 'price', 'agreement_number']),
            // 04/11/2026: fincas activas (y las desactivadas que ya usa alguna compra) para elegir por productor
            'farms'      => Farm::where('status', true)->orWhereIn('id', $lineas->pluck('farm_id')->filter())
                ->orderBy('name')->get(['id', 'third_party_id', 'name', 'magap_code', 'zone', 'status']),
            // 06/11/2026: contratos de los vendedores (activos y los ya usados) para elegir en las compras de CONTRATO
            'contracts'  => Contract::where('status', true)->orWhereIn('id', $lineas->pluck('contract_id')->filter())
                ->orderBy('third_party_id')->orderBy('number')
                ->get(['id', 'third_party_id', 'number', 'price', 'weekly_boxes', 'discount_condition', 'start_date', 'end_date', 'status']),
            // 10/11/2026: semanas en que se puede registrar la compra (la del pedido y la siguiente)
            'purchase_weeks' => array_values($this->semanasDeCompra($pedido)),
            'version'    => $this->version($pedido->id),
        ];
    }

    // POST /customer-orders/{id}/purchases/farms → finca nueva desde la compra (04/11/2026). Comercial no
    // tiene la pantalla Fincas; la finca queda en el catálogo para las próximas compras.
    public function crearFinca(Request $request, $orderId)
    {
        CustomerOrder::findOrFail($orderId);

        $data = $request->validate([
            'third_party_id' => 'required|exists:third_parties,id',
            'name'           => 'required|string|max:255',
            'magap_code'     => 'nullable|string|max:50',
            'zone'           => 'nullable|string|max:100',
        ], [
            'name.required' => 'ESCRIBE EL NOMBRE DE LA FINCA.',
        ]);

        $dueno = ThirdParty::find($data['third_party_id']);
        if (!in_array($dueno->type, self::TIPOS_VENDEDOR) || !$dueno->status) {
            throw ValidationException::withMessages(['third_party_id' => "{$dueno->name} NO ES UN PRODUCTOR O COMERCIAL ACTIVO."]);
        }
        $mayus = fn ($v) => isset($v) && trim($v) !== '' ? mb_strtoupper(trim($v)) : null;
        $nombre = $mayus($data['name']);
        if (Farm::where('third_party_id', $dueno->id)->whereRaw('UPPER(TRIM(name)) = ?', [$nombre])->exists()) {
            throw ValidationException::withMessages(['name' => "{$dueno->name} YA TIENE UNA FINCA LLAMADA $nombre."]);
        }

        $finca = Farm::create([
            'third_party_id' => $dueno->id,
            'name'           => $nombre,
            'magap_code'     => $mayus($data['magap_code'] ?? null),
            'zone'           => $mayus($data['zone'] ?? null),
            'status'         => true,
        ]);

        return response()->json($finca->only(['id', 'third_party_id', 'name', 'magap_code', 'zone', 'status']), 201);
    }
}
