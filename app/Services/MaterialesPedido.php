<?php

// MATERIALES DEL PEDIDO (preliminar) — 16/11/2026
// Ubicación: app/Services/MaterialesPedido.php
//
// Cuánto material necesita un pedido semanal y si alcanza lo que hay en bodegas:
//   necesario = Σ (cajas de la línea × ratio_per_box de la receta de su marca)  − lo ya retirado para el pedido
//   stock     = suma de todas las bodegas activas (y el detalle por bodega)
//   faltante  = necesario − stock (si es positivo)
//   cajas que alcanzan = cajas del pedido × la menor cobertura entre sus insumos ((retirado + stock) ÷ fórmula)
// Costo = necesario completo (fórmula) × costo promedio del insumo; solo lo reciben GERENCIA, FINANZAS y ADMIN.
// PACKED es presentación, no marca: si la línea es PACKED y su marca tiene receta PACKED propia, se usa esa.
// Es un cálculo de lectura: no guarda ni reserva nada.

namespace App\Services;

use App\Models\MaterialRecipe;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MaterialesPedido
{
    /** Marca → receta a usar cuando la línea es PACKED (si no está aquí, la de la marca) */
    public const RECETA_PACKED = ['PALMS_BANANA' => 'PALM_BANANAS_PACKED'];

    /** Tipos base que ven dólares */
    public const VEN_COSTO = ['ADMIN', 'GERENCIA', 'FINANZAS'];

    /**
     * @param array    $lineas  [['brand_id' => int, 'presentation' => string, 'boxes' => int], ...]
     * @param int|null $pedidoId para descontar lo ya retirado de bodega para ese pedido
     * @param User     $usuario  decide si ve costo y si ve todas las bodegas o solo la suya
     * @param bool     $conCosto false fuerza a no enviar dólares (pantalla de bodega)
     */
    public function calcular(array $lineas, ?int $pedidoId, User $usuario, bool $conCosto = true): array
    {
        $verCosto = $conCosto && in_array($usuario->role, self::VEN_COSTO, true);
        $marcas = DB::table('brands')->whereIn('id', collect($lineas)->pluck('brand_id')->filter()->unique())->get(['id', 'code', 'name'])->keyBy('id');
        $recetas = MaterialRecipe::with('supply')->where('status', true)->get()
            ->filter(fn ($r) => $r->supply && $r->supply->status)
            ->groupBy('brand');

        // 1) Fórmula por insumo (cajas × ratio) y marcas sin receta
        $formula = [];
        $insumos = [];
        $sinReceta = [];
        $cajas = 0;
        foreach ($lineas as $l) {
            $n = (int) ($l['boxes'] ?? 0);
            $marca = $marcas[$l['brand_id'] ?? 0] ?? null;
            if ($n <= 0 || !$marca) {
                continue;
            }
            $codigo = (($l['presentation'] ?? '') === 'PACKED' && isset(self::RECETA_PACKED[$marca->code], $recetas[self::RECETA_PACKED[$marca->code]]))
                ? self::RECETA_PACKED[$marca->code] : $marca->code;
            if (!isset($recetas[$codigo])) {
                $sinReceta[$marca->name] = ($sinReceta[$marca->name] ?? 0) + $n;
                continue;
            }
            // 16/11/2026: solo cuentan las cajas con receta (las otras van aparte en sin_receta)
            $cajas += $n;
            foreach ($recetas[$codigo]->keyBy('supply_id') as $r) {
                $formula[$r->supply_id] = ($formula[$r->supply_id] ?? 0) + $n * (float) $r->ratio_per_box;
                $insumos[$r->supply_id] = $r->supply;
            }
        }

        // 2) Lo ya retirado para este pedido (egresos − devoluciones de sus cupos)
        $retirado = $pedidoId && $insumos ? DB::table('inventory_movement_lines as l')
            ->join('inventory_movements as m', 'm.id', '=', 'l.inventory_movement_id')
            ->join('producer_quotas as q', 'q.id', '=', 'm.producer_quota_id')
            ->where('q.customer_order_id', $pedidoId)->where('m.status', true)
            ->whereIn('m.type', ['EGRESO', 'DEVOLUCION'])->whereIn('l.supply_id', array_keys($insumos))
            ->groupBy('l.supply_id')
            ->selectRaw("l.supply_id, SUM(CASE WHEN m.type = 'EGRESO' THEN l.quantity ELSE -l.quantity END) as cantidad")
            ->pluck('cantidad', 'supply_id') : collect();

        // 3) Stock por bodega activa (una consulta para todos los insumos)
        $bodegas = DB::table('warehouses')->where('status', true)->orderBy('name')->pluck('name', 'id');
        $stock = $insumos ? DB::table('inventory_movement_lines as l')
            ->join('inventory_movements as m', 'm.id', '=', 'l.inventory_movement_id')
            ->where('m.status', true)->whereIn('m.warehouse_id', $bodegas->keys())->whereIn('l.supply_id', array_keys($insumos))
            ->groupBy('l.supply_id', 'm.warehouse_id')
            ->selectRaw("l.supply_id, m.warehouse_id, SUM(CASE WHEN m.type IN ('INGRESO', 'DEVOLUCION') THEN l.quantity ELSE -l.quantity END) as cantidad")
            ->get()->groupBy('supply_id') : collect();

        // BODEGUERO: solo ve el stock de su bodega (el faltante sí se calcula con todas)
        $permitidas = $usuario->bodegasPermitidas();
        $verDetalle = $permitidas === null;

        $filas = collect($formula)->map(function ($f, $id) use ($insumos, $retirado, $stock, $bodegas, $permitidas, $verDetalle, $verCosto) {
            $s = $insumos[$id];
            $f = $this->redondear($f, $s->unit);
            $ya = max(0, (float) ($retirado[$id] ?? 0));
            $necesario = max(0, $this->redondear($f - $ya, $s->unit));
            $porBodega = collect($stock[$id] ?? [])->mapWithKeys(fn ($x) => [$x->warehouse_id => max(0, (float) $x->cantidad)]);
            $total = (float) $porBodega->sum();
            $fila = [
                'supply_id'     => (int) $id,
                'code'          => $s->code,
                'name'          => $s->name,
                'unit'          => $s->unit,
                'formula'       => $f,
                'ya_retirado'   => $ya,
                'necesario'     => $necesario,
                'stock'         => $verDetalle ? round($total, 2) : null,
                'stock_mi_bodega' => $verDetalle ? null : round((float) $porBodega->only($permitidas)->sum(), 2),
                'faltante'      => round(max(0, $necesario - $total), 2),
                'cobertura'     => $f > 0 ? min(1, ($ya + $total) / $f) : 1,
                'por_bodega'    => $verDetalle ? $bodegas->map(fn ($n, $w) => ['warehouse_id' => (int) $w, 'name' => $n, 'stock' => round($porBodega[$w] ?? 0, 2)])->values() : [],
            ];
            if ($verCosto) {
                $fila['costo_unitario'] = (float) $s->cost;
                $fila['costo'] = round($f * (float) $s->cost, 2);
            }
            return $fila;
        })->sortByDesc('faltante')->values();

        $cobertura = $filas->isEmpty() ? null : (float) $filas->min('cobertura');
        $res = [
            'cajas'          => $cajas,
            'cajas_alcanzan' => $cobertura === null ? null : (int) floor($cajas * $cobertura),
            'insumos'        => $filas->map(fn ($x) => collect($x)->except('cobertura')->all())->all(),
            'con_faltante'   => $filas->where('faltante', '>', 0)->count(),
            'sin_receta'     => collect($sinReceta)->map(fn ($n, $m) => ['marca' => $m, 'cajas' => $n])->values()->all(),
            'bodegas'        => $verDetalle ? $bodegas->count() : count($permitidas),
            'solo_mi_bodega' => !$verDetalle,
            've_costo'       => $verCosto,
        ];
        if ($verCosto) {
            $res['costo'] = round($filas->sum('costo'), 2);
            $res['costo_por_caja'] = $cajas > 0 ? round($res['costo'] / $cajas, 4) : null;
            $res['insumos_sin_costo'] = $filas->filter(fn ($x) => $x['costo_unitario'] <= 0)->count();
        }
        return $res;
    }

    /** Igual que el despacho: LIBRAS con 2 decimales; lo demás entero hacia arriba */
    private function redondear(float $valor, ?string $unidad): float
    {
        return $unidad === 'LIBRAS' ? round($valor, 2) : (float) ceil(round($valor, 6));
    }
}
