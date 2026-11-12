<?php

// TABLERO DE OPERACIONES — 11/11/2026
// Ubicación: app/Services/Tableros/TableroOperaciones.php
//
// Reemplaza la maqueta del Dashboard con datos reales. Todo cuelga de la SEMANA DEL PEDIDO
// (customer_orders.year/week): pedido → distribución en barcos → compra de fruta → cierre de embarque.
// Solo el cumplimiento de contratos de productores va por SEMANA DE COMPRA (producer_quotas.week_number),
// porque el cupo del contrato se cuenta así.
// Regla: este tablero NO calcula ni envía dinero (precios reales, oficiales ni de venta); eso va en el
// tablero financiero. Tampoco RUC ni cuentas: vendedores y clientes solo por nombre.

namespace App\Services\Tableros;

use App\Models\Contract;
use App\Models\CustomerOrderLine;
use App\Models\PurchaseExecutive;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TableroOperaciones
{
    /** Cumplimiento semanal de un contrato por debajo de este % genera alerta */
    private const UMBRAL_CONTRATO = 80;

    private array $pedidos;   // id => semana de los pedidos activos del rango

    public function __construct(private int $anio, private int $desde, private int $hasta, private array $filtros = [])
    {
        $this->pedidos = DB::table('customer_orders')->where('status', true)->where('year', $anio)
            ->whereBetween('week', [$desde, $hasta])->pluck('week', 'id')->all();
    }

    public function datos(): array
    {
        $embudo = $this->embudo();
        $contratos = $this->cumplimientoContratos();

        return [
            'rango'      => ['year' => $this->anio, 'week_from' => $this->desde, 'week_to' => $this->hasta],
            'semanas'    => $embudo,
            'totales'    => $this->totales($embudo),
            'por_cliente' => $this->porCliente(),
            'por_puerto' => $this->porPuerto(),
            'por_modalidad' => $this->porModalidad(),
            'barcos'     => $this->barcos(),
            'contratos'  => $contratos,
            'vendedores' => $this->vendedores(),
            'comerciales' => $this->comerciales(),
            'alertas'    => $this->alertas($contratos),
            'filtros'    => $this->opcionesFiltro(),
            'generado'   => now(config('app.zona_operacion'))->format('Y-m-d H:i'),
        ];
    }

    // ------------------------------------------------------------ consultas base (con filtros)

    private function ids(): array
    {
        return array_keys($this->pedidos) ?: [0];
    }

    /** Líneas del pedido: customer_order_lines col JOIN customer_orders co */
    private function lineasPedido()
    {
        $q = DB::table('customer_order_lines as col')->join('customer_orders as co', 'co.id', '=', 'col.customer_order_id')
            ->whereIn('co.id', $this->ids())->where('col.status', true);
        $this->filtrar($q, 'col.customer_id', 'col.brand_id', 'col.negotiation_type');
        return $q;
    }

    /** Lo distribuido en barcos: shipment_lines de barcos activos del pedido (la marca sale de la línea del pedido) */
    private function lineasBarco()
    {
        $q = DB::table('shipment_lines as sl')->join('shipments as s', 's.id', '=', 'sl.shipment_id')
            ->join('customer_orders as co', 'co.id', '=', 's.customer_order_id')
            ->leftJoin('customer_order_lines as col', 'col.id', '=', 'sl.customer_order_line_id')
            ->whereIn('co.id', $this->ids())->where('s.status', true)->where('sl.status', true);
        $this->filtrar($q, 'sl.customer_id', 'col.brand_id', 'sl.negotiation_type');
        return $q;
    }

    /** Compras de fruta: producer_quota_lines de los bloques del pedido (los cupos manuales de despacho no tienen bloque) */
    private function compras()
    {
        $q = DB::table('producer_quota_lines as pql')->join('purchase_blocks as pb', 'pb.id', '=', 'pql.purchase_block_id')
            ->join('customer_orders as co', 'co.id', '=', 'pb.customer_order_id')
            ->join('producer_quotas as pq', 'pq.id', '=', 'pql.producer_quota_id')
            ->whereIn('co.id', $this->ids());
        $this->filtrar($q, 'pb.customer_id', 'pb.brand_id', 'pql.negotiation_type');
        return $q;
    }

    /** Lo realmente embarcado: shipment_bookings del Cierre de embarque */
    private function cierres()
    {
        $q = DB::table('shipment_bookings as sb')->join('shipments as s', 's.id', '=', 'sb.shipment_id')
            ->join('customer_orders as co', 'co.id', '=', 's.customer_order_id')
            ->whereIn('co.id', $this->ids())->where('s.status', true);
        $this->filtrar($q, 'sb.customer_id', 'sb.brand_id', 'sb.negotiation_type');
        return $q;
    }

    private function hayFiltros(): bool
    {
        return !empty($this->filtros['customer_id']) || !empty($this->filtros['brand_id']) || !empty($this->filtros['negotiation_type']);
    }

    private function filtrar($q, string $cliente, string $marca, string $negociacion): void
    {
        if (!empty($this->filtros['customer_id'])) $q->where($cliente, $this->filtros['customer_id']);
        if (!empty($this->filtros['brand_id'])) $q->where($marca, $this->filtros['brand_id']);
        if (!empty($this->filtros['negotiation_type'])) $q->where($negociacion, $this->filtros['negotiation_type']);
    }

    // ------------------------------------------------------------ bloques

    /** Embudo por semana del pedido: pedido / distribuido / comprado / embarcado, y CONTRATO vs SPOT */
    private function embudo(): array
    {
        $pedido = $this->lineasPedido()->groupBy('co.week')
            ->selectRaw("co.week, SUM(col.boxes) t, SUM(CASE WHEN col.negotiation_type = 'CONTRATO' THEN col.boxes ELSE 0 END) c")->get()->keyBy('week');
        $distribuido = $this->lineasBarco()->groupBy('co.week')->selectRaw('co.week, SUM(sl.box_quantity) t')->pluck('t', 'week');
        $comprado = $this->compras()->groupBy('co.week')
            ->selectRaw("co.week, SUM(pql.quantity_cajas) t, SUM(CASE WHEN pql.negotiation_type = 'CONTRATO' THEN pql.quantity_cajas ELSE 0 END) c, COUNT(*) n")->get()->keyBy('week');
        $meta = DB::table('purchase_blocks as pb')->join('customer_orders as co', 'co.id', '=', 'pb.customer_order_id')->whereIn('co.id', $this->ids())
            ->when(!empty($this->filtros['customer_id']), fn ($q) => $q->where('pb.customer_id', $this->filtros['customer_id']))
            ->when(!empty($this->filtros['brand_id']), fn ($q) => $q->where('pb.brand_id', $this->filtros['brand_id']))
            ->groupBy('co.week')->selectRaw('co.week, SUM(pb.target_boxes) t')->pluck('t', 'week');
        // un barco cuenta "con cierre" solo si el cierre ya tiene cajas embarcadas (un booking vacío no cierra)
        $embarcado = $this->cierres()->groupBy('co.week')
            ->selectRaw('co.week, SUM(sb.shipped_boxes) t, COUNT(DISTINCT CASE WHEN sb.shipped_boxes > 0 THEN s.id END) n')->get()->keyBy('week');
        $barcos = DB::table('shipments')->whereIn('customer_order_id', $this->ids())->where('status', true)
            ->when($this->hayFiltros(), fn ($q) => $q->whereIn('id', $this->lineasBarco()->select('s.id')))
            ->groupBy('customer_order_id')->selectRaw('customer_order_id, COUNT(*) n')->pluck('n', 'customer_order_id');

        $filas = [];
        for ($w = $this->desde; $w <= $this->hasta; $w++) {
            $tienePedido = in_array($w, $this->pedidos);
            $filas[] = [
                'week'              => $w,
                'tiene_pedido'      => $tienePedido,
                'pedido'            => (int) ($pedido[$w]->t ?? 0),
                'pedido_contrato'   => (int) ($pedido[$w]->c ?? 0),
                'distribuido'       => (int) ($distribuido[$w] ?? 0),
                'meta_compra'       => (int) ($meta[$w] ?? 0),
                'comprado'          => (int) ($comprado[$w]->t ?? 0),
                'comprado_contrato' => (int) ($comprado[$w]->c ?? 0),
                'compras'           => (int) ($comprado[$w]->n ?? 0),
                'embarcado'         => (int) ($embarcado[$w]->t ?? 0),
                'barcos'            => (int) collect($this->pedidos)->filter(fn ($s) => (int) $s === $w)->keys()->sum(fn ($id) => $barcos[$id] ?? 0),
                'barcos_cerrados'   => (int) ($embarcado[$w]->n ?? 0),
            ];
        }
        return $filas;
    }

    private function totales(array $semanas): array
    {
        $s = collect($semanas);
        $sobre = $this->compras()->groupBy('pb.id', 'pb.target_boxes')
            ->selectRaw('pb.target_boxes meta, SUM(pql.quantity_cajas) c')->get()->sum(fn ($b) => max((int) $b->c - (int) $b->meta, 0));
        $envio = $this->compras()->selectRaw('COUNT(*) n, COUNT(pql.sent_to_warehouse_at) e')->first();
        return [
            'pedido'           => $s->sum('pedido'),
            'distribuido'      => $s->sum('distribuido'),
            'meta_compra'      => $s->sum('meta_compra'),
            'comprado'         => $s->sum('comprado'),
            'comprado_contrato' => $s->sum('comprado_contrato'),
            'sobre_compra'     => (int) $sobre,
            'embarcado'        => $s->sum('embarcado'),
            'barcos'           => $s->sum('barcos'),
            'barcos_cerrados'  => $s->sum('barcos_cerrados'),
            // distribuido de los barcos que ya tienen cierre: el % embarcado se mide solo sobre ellos
            'distribuido_cerrados' => (int) $this->lineasBarco()->whereExists(fn ($q) => $q->select(DB::raw(1))->from('shipment_bookings as x')
                ->whereColumn('x.shipment_id', 's.id')->where('x.shipped_boxes', '>', 0))->sum('sl.box_quantity'),
            'compras'          => (int) $envio->n,
            'enviadas_bodega'  => (int) $envio->e,
            'semanas_sin_compra' => $s->filter(fn ($w) => $w['tiene_pedido'] && $w['comprado'] === 0)->pluck('week')->values(),
        ];
    }

    /** Pedido y comprado por cliente (con su desglose por marca) */
    private function porCliente(): array
    {
        $pedido = $this->lineasPedido()->groupBy('col.customer_id', 'col.brand_id')->selectRaw('col.customer_id c, col.brand_id m, SUM(col.boxes) t')->get();
        $comprado = $this->compras()->groupBy('pb.customer_id', 'pb.brand_id')->selectRaw('pb.customer_id c, pb.brand_id m, SUM(pql.quantity_cajas) t')->get();
        $clientes = $this->nombresClientes($pedido->pluck('c')->merge($comprado->pluck('c'))->unique()->filter()->all());
        $marcas = DB::table('brands')->whereIn('id', $pedido->pluck('m')->merge($comprado->pluck('m'))->unique()->filter())->pluck('name', 'id');

        $filas = [];
        foreach ([['pedido', $pedido], ['comprado', $comprado]] as [$campo, $datos]) {
            foreach ($datos as $d) {
                $k = (int) $d->c;
                $filas[$k] ??= ['customer_id' => $k ?: null, 'cliente' => $clientes[$k] ?? 'SIN CLIENTE', 'pedido' => 0, 'comprado' => 0, 'marcas' => []];
                $filas[$k][$campo] += (int) $d->t;
                $marca = $marcas[$d->m] ?? 'SIN MARCA';
                $filas[$k]['marcas'][$marca] ??= ['marca' => $marca, 'pedido' => 0, 'comprado' => 0];
                $filas[$k]['marcas'][$marca][$campo] += (int) $d->t;
            }
        }
        return collect($filas)->map(fn ($f) => ['marcas' => collect($f['marcas'])->sortByDesc('pedido')->values()->all()] + $f)
            ->sortByDesc(fn ($f) => max($f['pedido'], $f['comprado']))->values()->all();
    }

    /** Por puerto de salida: distribuido (barco) y comprado (bloque) */
    private function porPuerto(): array
    {
        $dist = $this->lineasBarco()->groupBy('s.departure_port_id')->selectRaw('s.departure_port_id p, SUM(sl.box_quantity) t')->pluck('t', 'p');
        $comp = $this->compras()->groupBy('pb.departure_port_id')->selectRaw('pb.departure_port_id p, SUM(pql.quantity_cajas) t')->pluck('t', 'p');
        $puertos = DB::table('ports')->whereIn('id', $dist->keys()->merge($comp->keys())->filter())->pluck('name', 'id');
        return $dist->keys()->merge($comp->keys())->unique()->map(fn ($p) => [
            'puerto'      => $puertos[$p] ?? 'SIN PUERTO',
            'distribuido' => (int) ($dist[$p] ?? 0),
            'comprado'    => (int) ($comp[$p] ?? 0),
        ])->sortByDesc(fn ($f) => max($f['distribuido'], $f['comprado']))->values()->all();
    }

    /** Bajo cubierta vs contenedor (y granel/caja) según la modalidad de la línea del pedido */
    private function porModalidad(): array
    {
        return $this->lineasPedido()->groupBy('col.cargo_type')->selectRaw('col.cargo_type m, SUM(col.boxes) t')->get()
            ->map(fn ($r) => ['modalidad' => $r->m, 'contenedor' => CustomerOrderLine::esContenedor($r->m), 'pedido' => (int) $r->t])
            ->sortByDesc('pedido')->values()->all();
    }

    /** Barcos del rango con distribuido / comprado / embarcado y su estado */
    private function barcos(): array
    {
        $barcos = DB::table('shipments as s')->join('customer_orders as co', 'co.id', '=', 's.customer_order_id')
            ->leftJoin('vessels as v', 'v.id', '=', 's.vessel_id')->leftJoin('ports as p', 'p.id', '=', 's.departure_port_id')
            ->whereIn('co.id', $this->ids())->where('s.status', true)
            ->when($this->hayFiltros(), fn ($q) => $q->whereIn('s.id', $this->lineasBarco()->select('s.id')))
            ->orderBy('co.week')->orderBy('s.departure_date')->orderBy('s.id')
            ->get(['s.id', 'co.week', 'v.name as vessel', 's.vessel_name', 's.voyage', 'p.name as puerto', 's.departure_date', 's.eta']);
        $dist = $this->lineasBarco()->groupBy('s.id')->selectRaw('s.id, SUM(sl.box_quantity) t')->pluck('t', 'id');
        $comp = $this->compras()->groupBy('pb.shipment_id')->selectRaw('pb.shipment_id id, SUM(pql.quantity_cajas) t')->pluck('t', 'id');
        $emb = $this->cierres()->groupBy('s.id')->selectRaw('s.id, SUM(sb.shipped_boxes) t')->pluck('t', 'id');

        return $barcos->map(function ($b) use ($dist, $comp, $emb) {
            $nombre = $b->vessel ?: $b->vessel_name;
            $d = (int) ($dist[$b->id] ?? 0); $c = (int) ($comp[$b->id] ?? 0); $e = (int) ($emb[$b->id] ?? 0);
            return [
                'id'             => $b->id,
                'week'           => (int) $b->week,
                'barco'          => trim($nombre . ($b->voyage && !str_contains((string) $nombre, (string) $b->voyage) ? " {$b->voyage}" : '')),
                'puerto'         => $b->puerto,
                'departure_date' => $b->departure_date ? substr($b->departure_date, 0, 10) : null,
                'eta'            => $b->eta ? substr($b->eta, 0, 10) : null,
                'distribuido'    => $d,
                'comprado'       => $c,
                'embarcado'      => $e,
                'estado'         => $e > 0 ? 'EMBARCADO' : ($c === 0 ? 'SIN COMPRA' : ($c >= $d ? 'COMPLETO' : 'COMPRANDO')),
            ];
        })->values()->all();
    }

    /**
     * Cumplimiento del cupo semanal de los contratos de productores, por SEMANA DE COMPRA.
     * Se cuentan las semanas del rango en que el contrato está vigente y que ya empezaron (no las futuras).
     * 11/11/2026: y solo las semanas de compra que ya tienen compras en el sistema; mientras el cuadre histórico
     * no cargue una semana (ej. WK41-44), contarla como "0 comprado" mostraría incumplimientos falsos.
     */
    private function cumplimientoContratos(): array
    {
        $lunesDesde = Carbon::now()->setISODate($this->anio, $this->desde)->startOfWeek()->toDateString();
        $domingoHasta = Carbon::now()->setISODate($this->anio, $this->hasta)->endOfWeek()->toDateString();
        $contratos = Contract::where('status', true)
            ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $domingoHasta))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $lunesDesde))
            ->orderBy('third_party_id')->orderBy('number')->get();
        if ($contratos->isEmpty()) return [];

        $compradas = DB::table('producer_quota_lines as pql')->join('producer_quotas as pq', 'pq.id', '=', 'pql.producer_quota_id')
            ->whereIn('pql.contract_id', $contratos->pluck('id'))->where('pq.year', $this->anio)->whereBetween('pq.week_number', [$this->desde, $this->hasta])
            ->groupBy('pql.contract_id', 'pq.week_number')->selectRaw('pql.contract_id k, pq.week_number w, SUM(pql.quantity_cajas) t')->get()
            ->groupBy('k');
        $vendedores = DB::table('third_parties')->whereIn('id', $contratos->pluck('third_party_id'))->pluck('name', 'id');
        $hoy = now(config('app.zona_operacion'));
        $ultima = $hoy->isoWeekYear() === $this->anio ? min($this->hasta, $hoy->isoWeek()) : ($hoy->isoWeekYear() > $this->anio ? $this->hasta : $this->desde - 1);
        $conCompras = DB::table('producer_quota_lines as pql')->join('producer_quotas as pq', 'pq.id', '=', 'pql.producer_quota_id')
            ->whereNotNull('pql.purchase_block_id')->where('pq.year', $this->anio)->whereBetween('pq.week_number', [$this->desde, $this->hasta])
            ->distinct()->pluck('pq.week_number')->map(fn ($w) => (int) $w)->all();

        return $contratos->map(function ($k) use ($compradas, $vendedores, $ultima, $conCompras) {
            $porSemana = collect($compradas[$k->id] ?? [])->pluck('t', 'w')->map(fn ($t) => (int) $t);
            $semanas = collect(range($this->desde, max($this->desde, $ultima)))
                ->filter(fn ($w) => $w <= $ultima && in_array($w, $conCompras, true) && $k->vigenteEnSemana($this->anio, $w))->values();
            $cupo = (int) $k->weekly_boxes * $semanas->count();
            // solo lo comprado en las semanas que cuentan para el cupo (si no, el % se infla)
            $total = $semanas->sum(fn ($w) => $porSemana[$w] ?? 0);
            $semanaActual = $semanas->last();
            return [
                'id'                 => $k->id,
                'vendedor'           => $vendedores[$k->third_party_id] ?? '',
                'numero'             => $k->number,
                'finca'              => $k->farm_name,
                'codigo_bananero'    => $k->banana_code,
                'cupo_semanal'       => (int) $k->weekly_boxes,
                'semanas'            => $semanas->count(),
                'cupo_rango'         => $cupo,
                'comprado'           => $total,
                'porcentaje'         => $cupo > 0 ? round($total / $cupo * 100, 1) : null,
                'semanas_sin_compra' => $semanas->filter(fn ($w) => ($porSemana[$w] ?? 0) === 0)->count(),
                'ultima_semana'      => $semanaActual,
                'comprado_ultima'    => $semanaActual ? (int) ($porSemana[$semanaActual] ?? 0) : 0,
                'end_date'           => $k->end_date?->toDateString(),
            ];
        })->sortBy(fn ($f) => $f['porcentaje'] ?? 999)->values()->all();
    }

    /** Vendedores (productores / comercializadoras) con más cajas. Sin RUC. */
    private function vendedores(): array
    {
        $filas = $this->compras()->join('third_parties as tp', 'tp.id', '=', 'pq.third_party_id')
            ->groupBy('tp.id', 'tp.name', 'tp.type')
            ->selectRaw('tp.name, tp.type, SUM(pql.quantity_cajas) t, COUNT(*) n, COUNT(DISTINCT co.week) w')
            ->orderByDesc('t')->get();
        $total = max((int) $filas->sum('t'), 1);
        return $filas->take(15)->map(fn ($r) => [
            'vendedor' => $r->name, 'tipo' => $r->type, 'cajas' => (int) $r->t, 'compras' => (int) $r->n,
            'semanas' => (int) $r->w, 'porcentaje' => round($r->t / $total * 100, 1),
        ])->values()->all();
    }

    /** Comerciales: una compra con dos comerciales ("A / B") cuenta para ambos */
    private function comerciales(): array
    {
        $porTexto = $this->compras()->join('third_parties as tp', 'tp.id', '=', 'pq.third_party_id')->groupBy('pql.executive', 'tp.id')
            ->selectRaw('pql.executive e, tp.id v, SUM(pql.quantity_cajas) t, COUNT(*) n')->get();
        $filas = [];
        foreach ($porTexto as $r) {
            foreach (PurchaseExecutive::nombres($r->e) ?: ['SIN COMERCIAL'] as $nombre) {
                $filas[$nombre] ??= ['comercial' => $nombre, 'cajas' => 0, 'compras' => 0, 'vendedores' => []];
                $filas[$nombre]['cajas'] += (int) $r->t;
                $filas[$nombre]['compras'] += (int) $r->n;
                $filas[$nombre]['vendedores'][$r->v] = true;
            }
        }
        return collect($filas)->map(fn ($f) => ['vendedores' => count($f['vendedores'])] + $f)->sortByDesc('cajas')->values()->all();
    }

    /** Alertas por reglas (no hay columna "validado"). Cada una dice qué pantalla la corrige. Sin montos. */
    private function alertas(array $contratos): array
    {
        $hoy = now(config('app.zona_operacion'));
        $nombres = fn ($q) => $q->join('third_parties as tp', 'tp.id', '=', 'pq.third_party_id');
        $ejemplos = fn ($filas) => $filas->take(8)->map(fn ($r) => "WK{$r->week} · {$r->name} · " . number_format((int) $r->c, 0, ',', '.') . ' cajas')->values()->all();
        $compras = fn () => $nombres($this->compras())->select('co.week', 'tp.name', 'pql.quantity_cajas as c')->orderBy('co.week');
        $alertas = [];
        $agregar = function ($codigo, $nivel, $titulo, $ruta, $cantidad, $cajas = null, $detalle = []) use (&$alertas) {
            if ($cantidad > 0) $alertas[] = compact('codigo', 'nivel', 'titulo', 'ruta', 'cantidad', 'cajas', 'detalle');
        };

        // AL1 pedido con cajas sin distribuir en barcos
        $pedido = $this->lineasPedido()->groupBy('co.week')->selectRaw('co.week, SUM(col.boxes) t')->pluck('t', 'week');
        $dist = $this->lineasBarco()->groupBy('co.week')->selectRaw('co.week, SUM(sl.box_quantity) t')->pluck('t', 'week');
        $faltan = $pedido->map(fn ($t, $w) => (int) $t - (int) ($dist[$w] ?? 0))->filter(fn ($f) => $f > 0);
        $agregar('AL1', 'alerta', 'Pedidos con cajas sin distribuir en barcos', '/exportaciones/embarques', $faltan->count(), $faltan->sum(),
            $faltan->map(fn ($f, $w) => "WK$w · faltan " . number_format($f, 0, ',', '.') . ' cajas')->values()->all());

        // AL2 bloques sobre-comprados / AL3 bloques sin compras con zarpe en ≤ 3 días
        $bloques = DB::table('purchase_blocks as pb')->join('customer_orders as co', 'co.id', '=', 'pb.customer_order_id')
            ->leftJoin('shipments as s', 's.id', '=', 'pb.shipment_id')->leftJoin('customers as cu', 'cu.id', '=', 'pb.customer_id')
            ->leftJoin('third_parties as tc', 'tc.id', '=', 'cu.third_party_id')->leftJoin('brands as b', 'b.id', '=', 'pb.brand_id')
            ->whereIn('co.id', $this->ids())
            ->when(!empty($this->filtros['customer_id']), fn ($q) => $q->where('pb.customer_id', $this->filtros['customer_id']))
            ->when(!empty($this->filtros['brand_id']), fn ($q) => $q->where('pb.brand_id', $this->filtros['brand_id']))
            ->selectRaw('co.week, pb.target_boxes meta, s.departure_date, tc.name cliente, b.name marca, '
                . '(SELECT COALESCE(SUM(x.quantity_cajas), 0) FROM producer_quota_lines x WHERE x.purchase_block_id = pb.id) comprado')
            ->orderBy('co.week')->get();
        $sobre = $bloques->filter(fn ($b) => (int) $b->comprado > (int) $b->meta && (int) $b->meta > 0);
        $agregar('AL2', 'aviso', 'Bloques con más compra que la meta', '/comercial/compras', $sobre->count(), $sobre->sum(fn ($b) => $b->comprado - $b->meta),
            $sobre->take(8)->map(fn ($b) => "WK{$b->week} · {$b->cliente} · {$b->marca} · +" . number_format($b->comprado - $b->meta, 0, ',', '.') . ' cajas')->values()->all());
        $limite = $hoy->copy()->addDays(3)->toDateString();
        $sinCompra = $bloques->filter(fn ($b) => (int) $b->meta > 0 && (int) $b->comprado === 0 && $b->departure_date && substr($b->departure_date, 0, 10) <= $limite
            && substr($b->departure_date, 0, 10) >= $hoy->copy()->subDays(7)->toDateString());
        $agregar('AL3', 'critico', 'Bloques sin compras y el barco zarpa pronto', '/comercial/compras', $sinCompra->count(), $sinCompra->sum('meta'),
            $sinCompra->take(8)->map(fn ($b) => "WK{$b->week} · {$b->cliente} · {$b->marca} · zarpa " . Carbon::parse($b->departure_date)->format('d/m'))->values()->all());

        // AL4 compra CONTRATO sin contrato
        $f = $compras()->where('pql.negotiation_type', 'CONTRATO')->whereNull('pql.contract_id')->get();
        $agregar('AL4', 'alerta', 'Compras CONTRATO sin contrato asignado', '/comercial/compras', $f->count(), $f->sum('c'), $ejemplos($f));
        // AL5 comercializadora sin productor real
        $f = $compras()->where('tp.type', 'COMERCIALIZADORA')->whereNull('pql.producer_id')->get();
        $agregar('AL5', 'alerta', 'Compras a comercializadora sin productor', '/comercial/compras', $f->count(), $f->sum('c'), $ejemplos($f));
        // AL6 compra sin precio real (solo el conteo, sin montos)
        $f = $compras()->whereNull('pql.real_price')->get();
        $agregar('AL6', 'alerta', 'Compras sin precio real', '/comercial/compras', $f->count(), $f->sum('c'), $ejemplos($f));
        // AL7 compra cambiada después de enviarla a bodega
        $f = $compras()->whereNotNull('pql.changed_after_sent_at')->get();
        $agregar('AL7', 'aviso', 'Compras cambiadas después de enviarlas a bodega', '/despacho-materiales/pendientes', $f->count(), $f->sum('c'), $ejemplos($f));
        // AL8 compra no enviada a bodega con corte hasta mañana (solo las que aún no pasan: las históricas no se envían)
        $f = $compras()->whereNull('pql.sent_to_warehouse_at')->whereBetween('pql.cut_date', [$hoy->toDateString(), $hoy->copy()->addDay()->toDateString()])->get();
        $agregar('AL8', 'critico', 'Compras con corte hoy o mañana sin enviar a bodega', '/comercial/compras', $f->count(), $f->sum('c'), $ejemplos($f));

        // AL9 contratos bajo el umbral en la última semana / AL10 contratos que vencen en ≤ 30 días
        $bajo = collect($contratos)->filter(fn ($k) => $k['ultima_semana'] && $k['cupo_semanal'] > 0 && $k['comprado_ultima'] / $k['cupo_semanal'] * 100 < self::UMBRAL_CONTRATO);
        $agregar('AL9', 'aviso', 'Contratos con menos del ' . self::UMBRAL_CONTRATO . ' % del cupo en su última semana', '/liquidacion/contratos', $bajo->count(), null,
            $bajo->take(8)->map(fn ($k) => "WK{$k['ultima_semana']} · {$k['vendedor']} N°{$k['numero']} · " . number_format($k['comprado_ultima'], 0, ',', '.') . ' de ' . number_format($k['cupo_semanal'], 0, ',', '.'))->values()->all());
        $vencen = collect($contratos)->filter(fn ($k) => $k['end_date'] && $k['end_date'] >= $hoy->toDateString() && $k['end_date'] <= $hoy->copy()->addDays(30)->toDateString());
        $agregar('AL10', 'aviso', 'Contratos de productores que vencen en 30 días o menos', '/liquidacion/contratos', $vencen->count(), null,
            $vencen->take(8)->map(fn ($k) => "{$k['vendedor']} N°{$k['numero']} · vence " . Carbon::parse($k['end_date'])->format('d/m/Y'))->values()->all());

        // AL11 pedidos históricos por validar con Comercial
        $f = DB::table('customer_orders')->whereIn('id', $this->ids())->where('comment', 'ilike', '%POR VALIDAR%')->orderBy('week')->pluck('week');
        $agregar('AL11', 'aviso', 'Pedidos marcados POR VALIDAR por Comercial', '/exportaciones/pedidos', $f->count(), null, $f->map(fn ($w) => "WK$w")->all());

        // AL12 barcos que ya zarparon sin cierre de embarque
        $f = DB::table('shipments as s')->join('customer_orders as co', 'co.id', '=', 's.customer_order_id')->leftJoin('vessels as v', 'v.id', '=', 's.vessel_id')
            ->whereIn('co.id', $this->ids())->where('s.status', true)->where('s.departure_date', '<', $hoy->toDateString())
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('shipment_bookings as x')->whereColumn('x.shipment_id', 's.id')->where('x.shipped_boxes', '>', 0))
            ->orderBy('co.week')->get(['co.week', DB::raw('COALESCE(v.name, s.vessel_name) as name'), 's.departure_date']);
        $agregar('AL12', 'aviso', 'Barcos que ya zarparon sin cierre de embarque', '/exportaciones/cierre', $f->count(), null,
            $f->take(8)->map(fn ($r) => "WK{$r->week} · {$r->name} · zarpó " . Carbon::parse($r->departure_date)->format('d/m'))->values()->all());

        $orden = ['critico' => 0, 'alerta' => 1, 'aviso' => 2];
        return collect($alertas)->sortBy(fn ($a) => $orden[$a['nivel']])->values()->all();
    }

    /** Opciones de los filtros: clientes y marcas que aparecen en el rango (solo id y nombre) */
    private function opcionesFiltro(): array
    {
        $lineas = DB::table('customer_order_lines')->whereIn('customer_order_id', $this->ids())->where('status', true);
        $clientes = $this->nombresClientes((clone $lineas)->distinct()->pluck('customer_id')->filter()->all());
        return [
            'clientes' => collect($clientes)->map(fn ($n, $id) => ['id' => $id, 'name' => $n])->sortBy('name')->values()->all(),
            'marcas'   => DB::table('brands')->whereIn('id', (clone $lineas)->distinct()->pluck('brand_id')->filter())->orderBy('name')->get(['id', 'name'])->all(),
        ];
    }

    private function nombresClientes(array $ids): array
    {
        return DB::table('customers as cu')->join('third_parties as tp', 'tp.id', '=', 'cu.third_party_id')
            ->whereIn('cu.id', $ids ?: [0])->pluck('tp.name', 'cu.id')->all();
    }
}
