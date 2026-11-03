<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supply;
use App\Services\CostoPromedio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SupplyController extends Controller
{
    public function index(Request $request)
    {
        $query = Supply::with('category');

        // Permite filtrar por categoría: /supplieas?supply_category_id=1
        if ($request->filled('supply_category_id')) {
            $query->where('supply_category_id', $request->supply_category_id);
        }

        // Permite filtrar por proveedor (solo los insumos que ese proveedor vende):
        // /supplies?third_party_id=5
        if ($request->filled('third_party_id')) {
            $query->whereHas('thirdParties', function ($q) use ($request) {
                $q->where('third_parties.id', $request->third_party_id);
            });
        }

        // 03/11/2026: el costo promedio solo para quien tiene el permiso "Ver costos y valores"
        return response()->json($this->sinCostos($request->user(), $query->orderBy('name')->get()));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'supply_category_id' => 'required|exists:supply_categories,id',
            'code' => 'required|string|max:255|unique:supplies,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'unit' => 'required|string|max:50',
            'cost' => 'nullable|numeric|min:0',
        ]);

        if ($mensaje = $this->nombreRepetido($validated)) {
            return response()->json(['message' => $mensaje, 'errors' => ['name' => [$mensaje]]], 422);
        }

        $supply = Supply::create($validated);

        return response()->json($supply->load('category'), 201);
    }

    public function show(Request $request, $id)
    {
        return response()->json($this->sinCostos($request->user(), Supply::with('category')->findOrFail($id)));
    }

    public function update(Request $request, $id)
    {
        $supply = Supply::findOrFail($id);

        $validated = $request->validate([
            'supply_category_id' => 'required|exists:supply_categories,id',
            'code' => ['required', 'string', 'max:255', Rule::unique('supplies', 'code')->ignore($supply->id)],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'unit' => 'required|string|max:50',
            'cost' => 'nullable|numeric|min:0',
        ]);

        if ($mensaje = $this->nombreRepetido($validated, $supply->id)) {
            return response()->json(['message' => $mensaje, 'errors' => ['name' => [$mensaje]]], 422);
        }

        // 31/10/2026: si el insumo ya tiene compras, su costo es el PROMEDIO de ellas (CostoPromedio):
        // no se cambia a mano; se corrige corrigiendo la compra. Sin compras, es un costo de referencia.
        if (isset($validated['cost']) && abs((float) $validated['cost'] - (float) $supply->cost) > 0.0000005 && CostoPromedio::tieneCompras($supply->id)) {
            return response()->json([
                'message' => 'El costo de este insumo es el promedio de sus compras: no se cambia a mano. Si una compra tiene mal el precio, corrígela en Movimientos y kárdex.',
            ], 422);
        }

        // Si el precio cambió, se guarda el histórico antes de actualizar
       if (isset($validated['cost']) && abs((float) $validated['cost'] - (float) $supply->cost) > 0.0000005) {
    \App\Models\SupplyPriceHistory::create([
        'supply_id' => $supply->id,
        'old_cost' => $supply->cost,
        'new_cost' => $validated['cost'],
        'changed_by' => $request->user()->id,
        'changed_at' => now(),
    ]);
}

        $supply->update($validated);

        return response()->json($supply->load('category'));
    }

    /**
     * 03/11/2026: dónde se usa un insumo (recetas activas, stock por bodega, movimientos). La pantalla lo
     * muestra antes de desactivarlo: afecta kárdex, costo promedio, recetas y la liquidación de materiales.
     */
    public function usage($id)
    {
        return response()->json($this->uso(Supply::findOrFail($id)));
    }

    public function deactivate($id)
    {
        $supply = Supply::findOrFail($id);
        // 03/11/2026: no se desactiva si está en una receta activa o le queda stock en alguna bodega
        $uso = $this->uso($supply);
        if ($uso['bloqueo']) {
            return response()->json(['message' => $uso['bloqueo'], 'uso' => $uso], 422);
        }
        $supply->update(['status' => false]);

        return response()->json(['message' => 'Insumo desactivado correctamente']);
    }

    public function reactivate($id)
    {
        $supply = Supply::findOrFail($id);
        $supply->update(['status' => true]);

        return response()->json(['message' => 'Insumo reactivado correctamente']);
    }

    private function uso(Supply $supply): array
    {
        $recetas = DB::table('material_recipes as r')
            ->leftJoin('brands as b', 'b.code', '=', 'r.brand')
            ->where('r.supply_id', $supply->id)->where('r.status', true)
            ->selectRaw('COALESCE(b.name, r.brand) as marca')->pluck('marca')->unique()->values();
        $stock = DB::table('inventory_movement_lines as l')
            ->join('inventory_movements as m', 'm.id', '=', 'l.inventory_movement_id')
            ->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->where('l.supply_id', $supply->id)->where('m.status', true)
            ->groupBy('w.name')
            ->selectRaw("w.name as bodega, SUM(CASE WHEN m.type IN ('INGRESO', 'DEVOLUCION') THEN l.quantity ELSE -l.quantity END) as cantidad")
            ->get()->filter(fn ($s) => abs((float) $s->cantidad) > 0.0001)->values();
        $movimientos = DB::table('inventory_movement_lines as l')
            ->join('inventory_movements as m', 'm.id', '=', 'l.inventory_movement_id')
            ->where('l.supply_id', $supply->id)->where('m.status', true)->count();

        $bloqueo = null;
        if ($recetas->isNotEmpty()) {
            $bloqueo = 'Está en la receta de ' . $recetas->implode(', ') . ': primero cámbialo por otro insumo en Marcas y recetas.';
        } elseif ($stock->isNotEmpty()) {
            $bloqueo = 'Todavía tiene stock en ' . $stock->map(fn ($s) => "{$s->bodega} (" . round((float) $s->cantidad, 2) . ')')->implode(', ') . ': primero hay que darle salida o ajustarlo.';
        }

        return ['recetas' => $recetas, 'stock' => $stock, 'movimientos' => $movimientos, 'bloqueo' => $bloqueo];
    }

    // 03/11/2026: evita insumos duplicados: no se repite el mismo nombre en la misma categoría
    // (sin importar mayúsculas, tildes, espacios ni signos), aunque el otro esté inactivo.
    private function nombreRepetido(array $v, ?int $ignorar = null): ?string
    {
        $clave = fn ($n) => preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(Str::ascii((string) $n)));
        $igual = Supply::where('supply_category_id', $v['supply_category_id'])
            ->when($ignorar, fn ($q) => $q->where('id', '!=', $ignorar))
            ->get(['id', 'code', 'name', 'status'])
            ->first(fn ($s) => $clave($s->name) === $clave($v['name']));

        return $igual ? "Ya existe el insumo {$igual->code} {$igual->name}" . ($igual->status ? '' : ' (inactivo: reactívalo en vez de crear otro)') . '.' : null;
    }

}