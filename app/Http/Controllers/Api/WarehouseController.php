<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{
    /**
     * Lista todas las bodegas (activas e inactivas). El filtro de
     * Activos/Inactivos/Todos se hace en el frontend, igual que en
     * MovementReasonController@index, para que las inactivas también
     * se puedan ver y reactivar desde la UI.
     */
    public function index()
    {
        $warehouses = Warehouse::with('responsible')
            ->orderBy('name')
            ->get();

        return response()->json($warehouses);
    }

    /**
     * Crea una nueva bodega.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:warehouses,code',
            'responsible_user_id' => 'nullable|exists:users,id',
            'zone' => 'nullable|string|max:255',
        ]);

        $warehouse = Warehouse::create($validated);

        return response()->json($warehouse->load('responsible'), 201);
    }

    /**
     * Muestra una bodega puntual.
     */
    public function show($id)
    {
        $warehouse = Warehouse::with('responsible')->findOrFail($id);

        return response()->json($warehouse);
    }

    /**
     * Actualiza una bodega existente.
     */
    public function update(Request $request, $id)
    {
        $warehouse = Warehouse::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('warehouses', 'code')->ignore($warehouse->id),
            ],
            'responsible_user_id' => 'nullable|exists:users,id',
            'zone' => 'nullable|string|max:255',
        ]);

        $warehouse->update($validated);

        return response()->json($warehouse->load('responsible'));
    }

    /**
     * Soft delete: nunca se borra físicamente, solo status = false.
     * Renombrado de destroy() a deactivate() para seguir el estándar
     * del módulo Inventario (deactivate/reactivate, no destroy).
     */
    public function deactivate($id)
    {
        $warehouse = Warehouse::findOrFail($id);
        $warehouse->update(['status' => false]);

        return response()->json(['message' => 'Bodega desactivada correctamente']);
    }

    /**
     * Reactiva una bodega previamente desactivada.
     */
    public function reactivate($id)
    {
        $warehouse = Warehouse::findOrFail($id);
        $warehouse->update(['status' => true]);

        return response()->json(['message' => 'Bodega reactivada correctamente']);
    }
}