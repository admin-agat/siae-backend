<?php
 
namespace App\Http\Controllers\Api;
 
use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
 
class WarehouseController extends Controller
{
    /**
     * Lista todas las bodegas activas. La paginación y el filtro de
     * búsqueda se hacen en el frontend (mismo patrón que Fincas).
     */
    public function index()
    {
        $warehouses = Warehouse::with('responsible')
            ->where('status', true)
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
     */
    public function destroy($id)
    {
        $warehouse = Warehouse::findOrFail($id);
        $warehouse->update(['status' => false]);
 
        return response()->json(['message' => 'Bodega desactivada correctamente']);
    }
}
