<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supply;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplyController extends Controller
{
    public function index(Request $request)
    {
        $query = Supply::with('category');

        // Permite filtrar por categoría: /supplies?supply_category_id=1
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

        return response()->json($query->orderBy('name')->get());
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

        $supply = Supply::create($validated);

        return response()->json($supply->load('category'), 201);
    }

    public function show($id)
    {
        return response()->json(Supply::with('category')->findOrFail($id));
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

        // Si el precio cambió, se guarda el histórico antes de actualizar
        if (isset($validated['cost']) && $validated['cost'] != $supply->cost) {
            \App\Models\SupplyPriceHistory::create([
                'supply_id' => $supply->id,
                'old_cost' => $supply->cost,
                'new_cost' => $validated['cost'],
                'changed_by' => $request->user()->id,
            ]);
        }

        $supply->update($validated);

        return response()->json($supply->load('category'));
    }

    public function deactivate($id)
    {
        $supply = Supply::findOrFail($id);
        $supply->update(['status' => false]);

        return response()->json(['message' => 'Insumo desactivado correctamente']);
    }

    public function reactivate($id)
    {
        $supply = Supply::findOrFail($id);
        $supply->update(['status' => true]);

        return response()->json(['message' => 'Insumo reactivado correctamente']);
    }
    
}