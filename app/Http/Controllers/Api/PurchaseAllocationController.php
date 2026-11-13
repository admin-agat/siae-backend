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
use App\Services\Notificaciones;
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
            // 13/11/2026: compra global de una COMERCIALIZADORA sin productor ni finca hasta que llegue el cuadro
            'blocks.*.purchases.*.pending_split'     => 'nullable|boolean',
            // 13/11/2026: bono / extra al productor ($/caja por tarja, pago aparte de la fruta)
            'blocks.*.purchases.*.bonus_rate'        => 'nullable|numeric|gt:0|max:5',
            'blocks.*.purchases.*.bonus_reason'      => 'nullable|in:' . implode(',', ProducerQuotaLine::MOTIVOS_BONO),
            'blocks.*.purchases.*.bonus_detail'      => 'nullable|string|max:100',
        ], [
            'blocks.*.shipment_id.required'                 => 'CADA BLOQUE DEBE TENER BARCO.',
            'blocks.*.brand_id.required'                    => 'CADA BLOQUE DEBE TENER MARCA.',
            'blocks.*.presentation.required'                => 'CADA BLOQUE DEBE TENER PRESENTACIÓN (CONVENCIONAL O PACKED).',
            'blocks.*.customer_id.required'                 => 'CADA BLOQUE DEBE TENER CLIENTE.',
            'blocks.*.purchases.*.third_party_id.required'  => 'CADA COMPRA DEBE TENER A QUIÉN SE LE COMPRA (COMERCIAL O PRODUCTOR).',
            'blocks.*.purchases.*.boxes.min'                => 'LAS CAJAS DEBEN SER MAYORES A CERO.',
            'blocks.*.purchases.*.bonus_rate.gt'            => 'EL BONO DEBE SER MAYOR A 0 Y HASTA $ 5,00 POR CAJA.',
            'blocks.*.purchases.*.bonus_rate.max'           => 'EL BONO DEBE SER MAYOR A 0 Y HASTA $ 5,00 POR CAJA.',
            'blocks.*.purchases.*.bonus_reason.in'          => 'EL MOTIVO DEL BONO DEBE SER CINTA, EMPAQUE ESPECIAL, FRUTA ADICIONAL U OTRO.',
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
                // 13/11/2026: bono / extra: tarifa y motivo van juntos; OTRO pide detalle; CINTA solo en PACKED
                // (CINTA se valida solo si el bono es nuevo o cambió, para no trabar compras de antes)
                $tarifaBono = !empty($c['bonus_rate']) ? (float) $c['bonus_rate'] : null;
                $motivoBono = $c['bonus_reason'] ?? null;
                if ($tarifaBono && !$motivoBono) {
                    $errores["blocks.$b.purchases.$i.bonus_reason"] = "$nombreBloque, COMPRA $n: ELIGE EL MOTIVO DEL BONO (CINTA, EMPAQUE ESPECIAL, FRUTA ADICIONAL U OTRO).";
                } elseif (!$tarifaBono && $motivoBono) {
                    $errores["blocks.$b.purchases.$i.bonus_rate"] = "$nombreBloque, COMPRA $n: ESCRIBE EL BONO POR CAJA O QUITA EL MOTIVO.";
                } elseif ($motivoBono === 'OTRO' && trim($c['bonus_detail'] ?? '') === '') {
                    $errores["blocks.$b.purchases.$i.bonus_detail"] = "$nombreBloque, COMPRA $n: ESCRIBE EL DETALLE DEL BONO (MOTIVO OTRO).";
                } elseif ($motivoBono === 'CINTA' && $bloque['presentation'] !== 'PACKED'
                    && ($actualCompra?->bonus_reason !== 'CINTA' || (float) $actualCompra?->bonus_rate !== $tarifaBono)) {
                    $errores["blocks.$b.purchases.$i.bonus_reason"] = "$nombreBloque, COMPRA $n: EL BONO POR CINTA SOLO VA EN BLOQUES PACKED.";
                }
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
                // 13/11/2026: POR DIVIDIR (solo COMERCIALIZADORA) no pide productor ni finca; si ya llegan los
                // dos, la compra deja de estar por dividir (se completó a mano)
                if ($this->porDividir($c)) {
                    if ($vendedor->type !== 'COMERCIALIZADORA') {
                        $errores["blocks.$b.purchases.$i.pending_split"] = "$nombreBloque, COMPRA $n: SOLO UNA COMPRA A UNA COMERCIALIZADORA PUEDE QUEDAR POR DIVIDIR.";
                    }
                } elseif (!empty($c['farm_id'])) {
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
                // 13/11/2026: tampoco cambian el precio oficial (NC congelada) ni el bono
                || (float) ($nueva['official_price'] ?? 0) !== (float) $linea->official_price
                || (float) ($nueva['bonus_rate'] ?? 0) !== (float) $linea->bonus_rate
                || ($nueva['bonus_reason'] ?? null) !== $linea->bonus_reason
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
                    $porDividir = $this->porDividir($c); // 13/11/2026
                    $finca = !$porDividir && !empty($c['farm_id']) ? $fincas->get($c['farm_id']) : null;
                    if ($porDividir) {
                        $copiaFinca = [null, null];
                    } elseif ($finca && (int) $actual?->farm_id !== (int) $finca->id) {
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
                        'producer_id'         => !$porDividir && !empty($c['producer_id']) && (int) $c['producer_id'] !== (int) $c['third_party_id'] ? $c['producer_id'] : null,
                        'farm_id'             => $finca?->id,
                        'pending_split'       => $porDividir, // 13/11/2026
                        // 13/11/2026: bono / extra (sin tarifa, los tres quedan vacíos)
                        'bonus_rate'          => !empty($c['bonus_rate']) ? $c['bonus_rate'] : null,
                        'bonus_reason'        => !empty($c['bonus_rate']) ? ($c['bonus_reason'] ?? null) : null,
                        'bonus_detail'        => !empty($c['bonus_rate']) ? $mayus($c['bonus_detail'] ?? null) : null,
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
                    // 12/11/2026: aviso en la campanita del bodeguero de esa bodega y de los coordinadores
                    Notificaciones::envioBodega($linea, $request->user());
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
            // 12/11/2026: los avisos sin leer de ese envío quedan leídos (ya no hay nada que retirar)
            Notificaciones::envioQuitado($linea);
        });

        return response()->json($this->cargar($pedido->fresh()));
    }

    /**
     * POST /customer-orders/{id}/purchases/{lineId}/split — 13/11/2026: divide una compra (normalmente la
     * compra global POR DIVIDIR de una comercializadora) según el cuadro de despacho que manda el vendedor.
     * Body: { version, remainder: REDUCE|KEEP, rows: [{purchase_block_id, producer_id, farm_id, boxes,
     * real_price, official_price, payment_scheme, notes}] }. La compra original se conserva como la primera
     * fila de su bloque (mantiene su id, envío a bodega y avisos); las demás se crean copiando sus datos.
     * Diseño: docs/modulos/compra-global-division.md (siae-frontend).
     */
    public function dividir(Request $request, $orderId, $lineId)
    {
        $pedido = CustomerOrder::findOrFail($orderId);
        if (!$pedido->status) {
            return response()->json(['message' => 'EL PEDIDO ESTÁ ANULADO.'], 422);
        }
        $data = $request->validate([
            'version'                 => 'nullable|string',
            'remainder'               => 'nullable|in:REDUCE,KEEP',
            'rows'                    => 'required|array|min:1',
            'rows.*.purchase_block_id' => 'required|integer',
            'rows.*.producer_id'      => 'nullable|integer',
            'rows.*.farm_id'          => 'nullable|integer',
            'rows.*.boxes'            => 'required|integer|min:1',
            'rows.*.real_price'       => 'nullable|numeric|min:0',
            'rows.*.official_price'   => 'nullable|numeric|min:0',
            'rows.*.payment_scheme'   => 'nullable|in:100,80_20',
            'rows.*.notes'            => 'nullable|string|max:255',
        ], [
            'rows.required'                 => 'EL CUADRO NO TIENE FILAS.',
            'rows.*.boxes.required'         => 'CADA FILA DEBE TENER CAJAS.',
            'rows.*.boxes.min'              => 'LAS CAJAS DEBEN SER MAYORES A CERO.',
            'rows.*.purchase_block_id.required' => 'CADA FILA DEBE TENER BLOQUE (MARCA).',
        ]);

        [$divididas, $saldo] = DB::transaction(function () use ($pedido, $data, $request, $lineId) {
            CustomerOrder::whereKey($pedido->id)->lockForUpdate()->first();
            $this->verificarVersion($request, $this->version($pedido->id));

            $linea = $this->comprasDelPedido($pedido->id)->firstWhere('id', (int) $lineId);
            if (!$linea) {
                throw ValidationException::withMessages(['purchase' => 'LA COMPRA NO EXISTE O NO ES DE ESTA SEMANA: RECARGA LA PANTALLA.']);
            }
            $cupo = $linea->quota;
            $vendedor = $cupo->thirdParty;
            if ($cupo->status === ProducerQuota::LIQUIDADO) {
                throw ValidationException::withMessages(['purchase' => "NO SE PUEDE DIVIDIR: EL CUPO DE {$vendedor->name} WK{$cupo->week_number} YA FUE LIQUIDADO."]);
            }
            if ($linea->tallies()->exists()) {
                throw ValidationException::withMessages(['purchase' => 'NO SE PUEDE DIVIDIR: LA COMPRA YA TIENE TARJAS. DIVIDE ANTES DE REGISTRAR TARJAS O PIDE A LIQUIDACIONES QUE LAS ANULE.']);
            }

            $filas = $data['rows'];
            $comercializadora = $vendedor->type === 'COMERCIALIZADORA';
            $bloques = PurchaseBlock::where('customer_order_id', $pedido->id)->get()->keyBy('id');
            $bloqueOriginal = $bloques->get($linea->purchase_block_id);
            $productores = ThirdParty::whereIn('id', collect($filas)->pluck('producer_id')->filter()->unique())->get()->keyBy('id');
            $fincas = Farm::whereIn('id', collect($filas)->pluck('farm_id')->filter()->unique())->get()->keyBy('id');
            $suma = (int) collect($filas)->sum('boxes');
            $remainder = $data['remainder'] ?? null;

            $errores = [];
            if (count($filas) < 2 && !($remainder === 'KEEP' && $suma < $linea->quantity_cajas)) {
                $errores['rows'] = 'EL CUADRO DEBE TENER AL MENOS DOS FILAS.';
            }
            foreach ($filas as $i => $f) {
                $n = $i + 1;
                $bloque = $bloques->get($f['purchase_block_id']);
                if (!$bloque) {
                    $errores["rows.$i.purchase_block_id"] = "FILA $n: EL BLOQUE NO ES DE ESTE PEDIDO.";
                    continue;
                }
                // Comercializadora: cada fila es de un productor real. Compra directa: solo se divide por finca.
                $productor = !empty($f['producer_id']) ? $productores->get($f['producer_id']) : null;
                if ($comercializadora) {
                    if (empty($f['producer_id'])) {
                        $errores["rows.$i.producer_id"] = "FILA $n: ELIGE EL PRODUCTOR.";
                    } elseif (!$productor || $productor->type !== 'PRODUCTOR' || !$productor->status) {
                        $errores["rows.$i.producer_id"] = "FILA $n: " . ($productor->name ?? 'ESE TERCERO') . ' NO ES UN PRODUCTOR ACTIVO.';
                    }
                } elseif (!empty($f['producer_id']) && (int) $f['producer_id'] !== (int) $vendedor->id) {
                    $errores["rows.$i.producer_id"] = "FILA $n: EN COMPRA DIRECTA SOLO SE DIVIDE POR FINCA.";
                }
                // La finca es obligatoria, del productor de la fila (o del vendedor en compra directa) y activa
                $duenoId = $comercializadora ? (int) ($f['producer_id'] ?? 0) : (int) $vendedor->id;
                $finca = !empty($f['farm_id']) ? $fincas->get($f['farm_id']) : null;
                if (empty($f['farm_id'])) {
                    $errores["rows.$i.farm_id"] = "FILA $n: FALTA LA FINCA.";
                } elseif (!$finca) {
                    $errores["rows.$i.farm_id"] = "FILA $n: LA FINCA NO EXISTE.";
                } elseif ((int) $finca->third_party_id !== $duenoId) {
                    $dueno = $comercializadora ? ($productor->name ?? '') : $vendedor->name;
                    $errores["rows.$i.farm_id"] = "FILA $n: LA FINCA {$finca->name} NO ES DE $dueno.";
                } elseif (!$finca->status) {
                    $errores["rows.$i.farm_id"] = "FILA $n: LA FINCA {$finca->name} ESTÁ DESACTIVADA.";
                }
                // PACKED (banda) se compra siempre SPOT y con el precio negociado con cada productor
                if ($bloque->presentation === 'PACKED' && (int) $bloque->id !== (int) $linea->purchase_block_id
                    && !isset($f['real_price'])) {
                    $errores["rows.$i.real_price"] = "FILA $n: LA FRUTA PACKED SE COMPRA SIEMPRE SPOT: ESCRIBE EL PRECIO REAL.";
                }
            }
            if (!collect($filas)->contains(fn ($f) => (int) $f['purchase_block_id'] === (int) $linea->purchase_block_id)) {
                $errores['rows'] = "AL MENOS UNA FILA DEBE QUEDAR EN EL BLOQUE ORIGINAL ({$linea->brand?->name} {$bloqueOriginal?->presentation}).";
            }
            if ($suma < $linea->quantity_cajas) {
                if (!$remainder) {
                    $errores['remainder'] = 'LAS FILAS SUMAN ' . number_format($suma, 0, ',', '.') . ' DE ' . number_format($linea->quantity_cajas, 0, ',', '.')
                        . ' CAJAS: ELIGE SI BAJAS LA COMPRA O DEJAS EL SALDO POR DIVIDIR.';
                } elseif ($remainder === 'KEEP' && !$comercializadora) {
                    $errores['remainder'] = 'SOLO UNA COMPRA A UNA COMERCIALIZADORA PUEDE DEJAR SALDO POR DIVIDIR.';
                }
            }
            if ($errores) {
                throw ValidationException::withMessages($errores);
            }

            $mayus = fn ($v) => isset($v) && trim($v) !== '' ? mb_strtoupper(trim($v)) : null;
            $marcas = Brand::whereIn('id', $bloques->pluck('brand_id'))->get()->keyBy('id');
            $ahora = now();
            $quien = $request->user()?->id;
            $enviada = (bool) $linea->sent_to_warehouse_at;
            $original = (int) $linea->quantity_cajas;
            $saldo = $suma < $original && $remainder === 'KEEP' ? $original - $suma : 0;

            // Datos de una fila del cuadro (productor, finca con su copia de nombre y MAG, cajas, notas)
            $datosFila = function (array $f) use ($fincas, $comercializadora, $mayus) {
                $finca = $fincas->get($f['farm_id']);
                return [
                    'producer_id'    => $comercializadora ? $f['producer_id'] : null,
                    'farm_id'        => $finca->id,
                    'farm_name'      => $mayus($finca->name),
                    'mag_code'       => $mayus($finca->magap_code),
                    'quantity_cajas' => $f['boxes'],
                    'notes'          => $mayus($f['notes'] ?? null),
                    'pending_split'  => false,
                ];
            };

            // 1) La compra original toma la primera fila de su bloque
            $iPrimera = collect($filas)->search(fn ($f) => (int) $f['purchase_block_id'] === (int) $linea->purchase_block_id);
            $primera = $filas[$iPrimera];
            $linea->update($datosFila($primera) + [
                'real_price'     => $primera['real_price'] ?? $linea->real_price,
                'official_price' => $primera['official_price'] ?? $linea->official_price,
                'payment_scheme' => (string) ($primera['payment_scheme'] ?? $linea->payment_scheme),
                'split_at'       => $ahora,
                'split_by'       => $quien,
            ]);

            // 2) Las demás filas copian la compra original (vendedor, semana, comerciales, corte, entrega,
            //    bodega, precios, tipo, pago, contrato, transporte por caja y envío a bodega)
            $nuevas = [];
            $base = [
                'producer_quota_id'   => $linea->producer_quota_id,
                'executive'           => $linea->executive,
                'cut_date'            => $linea->cut_date,
                'delivery_port_id'    => $linea->delivery_port_id,
                'pickup_warehouse_id' => $linea->pickup_warehouse_id,
                // El transporte negociado es un monto de la compra: queda en la original (no se repite)
                'transport_deduction' => 0,
                'transport_recognized_rate' => $linea->transport_recognized_rate,
                // 13/11/2026: el bono es por caja: se copia (menos a PACKED desde una compra convencional, abajo)
                'bonus_rate'          => $linea->bonus_rate,
                'bonus_reason'        => $linea->bonus_reason,
                'bonus_detail'        => $linea->bonus_detail,
                'split_from_line_id'  => $linea->id,
                'split_at'            => $ahora,
                'split_by'            => $quien,
            ];
            foreach ($filas as $i => $f) {
                if ($i === $iPrimera) {
                    continue;
                }
                $bloque = $bloques->get($f['purchase_block_id']);
                $packed = $bloque->presentation === 'PACKED';
                $otraMarca = (int) $bloque->brand_id !== (int) $linea->brand_id;
                // 13/11/2026: a PACKED desde una compra convencional va sin bono (Comercial lo pone en la tabla)
                $sinBono = $packed && $bloqueOriginal?->presentation !== 'PACKED'
                    ? ['bonus_rate' => null, 'bonus_reason' => null, 'bonus_detail' => null] : [];
                $nuevas[] = ProducerQuotaLine::create($sinBono + $base + $datosFila($f) + [
                    'purchase_block_id' => $bloque->id,
                    'customer_id'       => $bloque->customer_id,
                    'brand_id'          => $bloque->brand_id,
                    'variety'           => $marcas[$bloque->brand_id]->code, // lo que lee el despacho
                    'official_price'    => $f['official_price'] ?? $linea->official_price,
                    'real_price'        => $f['real_price'] ?? $linea->real_price,
                    'payment_scheme'    => (string) ($f['payment_scheme'] ?? $linea->payment_scheme),
                    // PACKED (banda) siempre SPOT y sin contrato; el resto conserva el contrato del vendedor
                    'negotiation_type'  => $packed ? 'SPOT' : $linea->negotiation_type,
                    'contract_id'       => $packed ? null : $linea->contract_id,
                    'contract_part'     => $packed ? 0 : (int) $linea->contract_part,
                    // Misma marca: ya está enviada con la original. Marca nueva: se envía y avisa (abajo).
                    'sent_to_warehouse_at' => $enviada ? ($otraMarca ? $ahora : $linea->sent_to_warehouse_at) : null,
                    'sent_to_warehouse_by' => $enviada ? ($otraMarca ? $quien : $linea->sent_to_warehouse_by) : null,
                ]);
            }

            // 3) Saldo POR DIVIDIR (otro cuadro por llegar), en el bloque original y sin productor ni finca
            if ($saldo > 0) {
                $nuevas[] = ProducerQuotaLine::create($base + [
                    'purchase_block_id'    => $linea->purchase_block_id,
                    'customer_id'          => $linea->customer_id,
                    'brand_id'             => $linea->brand_id,
                    'variety'              => $linea->variety,
                    'quantity_cajas'       => $saldo,
                    'official_price'       => $linea->official_price,
                    'real_price'           => $linea->real_price,
                    'payment_scheme'       => $linea->payment_scheme,
                    'negotiation_type'     => $linea->negotiation_type,
                    'contract_id'          => $linea->contract_id,
                    'contract_part'        => (int) $linea->contract_part,
                    'pending_split'        => true,
                    'sent_to_warehouse_at' => $linea->sent_to_warehouse_at,
                    'sent_to_warehouse_by' => $linea->sent_to_warehouse_by,
                ]);
            }

            // 4) Bodega: si cambió el total de la marca original, la bandeja marca CAMBIÓ; las marcas nuevas
            //    reciben su aviso en la campanita (la bodega tiene que entregar otro material)
            if ($enviada) {
                $mismaMarca = collect($nuevas)->filter(fn ($l) => (int) $l->brand_id === (int) $linea->brand_id)->push($linea->fresh());
                if ((int) $mismaMarca->sum('quantity_cajas') !== $original) {
                    $mismaMarca->each(fn ($l) => $l->update(['changed_after_sent_at' => $ahora]));
                }
                foreach ($nuevas as $l) {
                    if ((int) $l->brand_id !== (int) $linea->brand_id) {
                        Notificaciones::envioBodega($l, $request->user());
                    }
                }
            }

            // 5) Total del cupo = suma de sus líneas
            $cupo->update(['total_cupo' => $cupo->lines()->sum('quantity_cajas')]);

            return [count($filas), $saldo];
        });

        return response()->json($this->cargar($pedido->fresh()) + ['divididas' => $divididas, 'saldo' => $saldo]);
    }

    // 13/11/2026: ¿la compra queda POR DIVIDIR? Marcada y sin productor + finca completos
    private function porDividir(array $c): bool
    {
        return !empty($c['pending_split']) && !(!empty($c['producer_id']) && !empty($c['farm_id']));
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
        return ProducerQuotaLine::with(['quota.thirdParty:id,name,type', 'brand:id,name,code', 'producer:id,name', 'sentBy:id,name', 'splitBy:id,name'])
            ->whereHas('block', fn ($q) => $q->where('customer_order_id', $pedidoId))
            ->whereNotNull('customer_id')
            ->orderBy('id')
            ->get();
    }

    /** 12/11/2026: [{contract_id, week, boxes}] de otros pedidos en las semanas de compra de este pedido. */
    private function usoContratosOtrosPedidos(CustomerOrder $pedido): array
    {
        $usos = [];
        foreach ($this->semanasDeCompra($pedido) as $s) {
            DB::table('producer_quota_lines as pql')
                ->join('producer_quotas as pq', 'pq.id', '=', 'pql.producer_quota_id')
                ->join('purchase_blocks as pb', 'pb.id', '=', 'pql.purchase_block_id')
                ->whereNotNull('pql.contract_id')->where('pb.customer_order_id', '<>', $pedido->id)
                ->where('pq.year', $s['year'])->where('pq.week_number', $s['week'])
                ->groupBy('pql.contract_id')->selectRaw('pql.contract_id, SUM(pql.quantity_cajas) as cajas')->get()
                ->each(function ($u) use (&$usos, $s) {
                    $usos[] = ['contract_id' => (int) $u->contract_id, 'week' => $s['week'], 'boxes' => (int) $u->cajas];
                });
        }
        return $usos;
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
                // 13/11/2026: compra global POR DIVIDIR y traza de la división
                'pending_split'       => (bool) $l->pending_split,
                'split_from_line_id'  => $l->split_from_line_id,
                'split_at'            => $l->split_at?->toIso8601String(),
                'split_by'            => $l->splitBy?->name,
                'bonus_rate'          => $l->bonus_rate,   // 13/11/2026: bono / extra
                'bonus_reason'        => $l->bonus_reason,
                'bonus_detail'        => $l->bonus_detail,
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
            // 12/11/2026: cajas compradas con cada contrato en OTROS pedidos, por semana de compra (el cupo es
            // semanal y un vendedor puede entregar en varios pedidos; lo de este pedido lo suma la pantalla)
            'contract_usage' => $this->usoContratosOtrosPedidos($pedido),
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
