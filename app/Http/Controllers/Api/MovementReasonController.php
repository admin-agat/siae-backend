<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MovementReason;
use Illuminate\Http\Request;

class MovementReasonController extends Controller
{
    // Devuelve TODOS los motivos (activos e inactivos), igual que SupplyCategoriesPage,
    // para poder mostrar la columna Estado y el botón reactivar en el frontend.
    // El frontend pagina/filtra localmente (mismo patrón que Insumos: get(), no paginate()).
    public function index(Request $request)
    {
        $query = MovementReason::query();

        // Filtro opcional por tipo: /movement-reasons?type=INGRESO
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Filtro opcional para pedir solo activos, útil desde el formulario de
        // Ingreso/Egreso donde no queremos mostrar motivos desactivados.
        if ($request->filled('solo_activos')) {
            $query->where('status', true);
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:INGRESO,EGRESO',
        ]);

        // Todo el contenido de catálogos se guarda en mayúsculas (estándar del sistema).
        $validated['name'] = mb_strtoupper($validated['name']);

        // Todo registro nuevo nace activo.
        $validated['status'] = true;

        $reason = MovementReason::create($validated);

        return response()->json($reason, 201);
    }

    public function show($id)
    {
        return response()->json(MovementReason::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $reason = MovementReason::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:INGRESO,EGRESO',
        ]);

        $validated['name'] = mb_strtoupper($validated['name']);

        $reason->update($validated);

        return response()->json($reason);
    }

    // Soft-delete reversible: apaga el registro pero no lo borra físicamente.
    public function deactivate($id)
    {
        $reason = MovementReason::findOrFail($id);
        $reason->update(['status' => false]);

        return response()->json(['message' => 'Motivo desactivado correctamente']);
    }

    // Reversa del deactivate.
    public function reactivate($id)
    {
        $reason = MovementReason::findOrFail($id);
        $reason->update(['status' => true]);

        return response()->json(['message' => 'Motivo reactivado correctamente']);
    }
}