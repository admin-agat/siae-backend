<?php

// Controlador de Fincas (solo ADMIN, por middleware en routes/api.php)
// Ubicación: app/Http/Controllers/Api/FarmController.php
//
// 27/10/2026: destroy() REEMPLAZADO por deactivate() + reactivate() — estándar del SIAE:
// baja lógica reversible con rutas PATCH, nunca DELETE (igual que Terceros y Bodegas).
// El resto (index/store/show/update) queda igual que antes.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Farm;
use Illuminate\Http\Request;

class FarmController extends Controller
{
    // GET /farms → lista con su productor (thirdParty). Devuelve activas e inactivas;
    // la pantalla las separa con las pestañas ACTIVOS / INACTIVOS / TODOS.
    public function index(Request $request)
    {
        $query = Farm::with('thirdParty');

        // Filtro opcional por productor (lo usan selectores de otros módulos)
        if ($request->has('third_party_id')) {
            $query->where('third_party_id', $request->third_party_id);
        }

        // Búsqueda opcional por nombre o código MAGAP
        if ($request->has('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'ilike', '%' . $request->search . '%')
                  ->orWhere('magap_code', 'ilike', '%' . $request->search . '%');
            });
        }

        return response()->json($query->orderBy('name')->get());
    }

    // POST /farms → crear finca
    public function store(Request $request)
    {
        $validated = $request->validate([
            'third_party_id' => 'required|exists:third_parties,id',
            'name' => 'required|string|max:255',
            'magap_code' => 'nullable|string|max:50',
            'zone' => 'nullable|string|max:100',
        ]);

        $farm = Farm::create($validated);
        return response()->json($farm->load('thirdParty'), 201);
    }

    // GET /farms/{id}
    public function show($id)
    {
        $farm = Farm::with('thirdParty')->findOrFail($id);
        return response()->json($farm);
    }

    // PUT /farms/{id} → actualizar finca
    public function update(Request $request, $id)
    {
        $farm = Farm::findOrFail($id);

        $validated = $request->validate([
            'third_party_id' => 'required|exists:third_parties,id',
            'name' => 'required|string|max:255',
            'magap_code' => 'nullable|string|max:50',
            'zone' => 'nullable|string|max:100',
        ]);

        $farm->update($validated);
        return response()->json($farm->load('thirdParty'));
    }

    // PATCH /farms/{id}/deactivate → baja lógica (status = false)
    // Hace lo mismo que el antiguo destroy(), pero con nombre y ruta estándar.
    public function deactivate($id)
    {
        $farm = Farm::findOrFail($id);
        $farm->update(['status' => false]); // nunca se borra físico

        return response()->json([
            'message' => 'FINCA DESACTIVADA',
            'data'    => $farm->fresh()->load('thirdParty'),
        ]);
    }

    // PATCH /farms/{id}/reactivate → vuelve a activar (status = true)
    // Antes no existía: una finca desactivada no tenía forma de volver.
    public function reactivate($id)
    {
        $farm = Farm::findOrFail($id);
        $farm->update(['status' => true]);

        return response()->json([
            'message' => 'FINCA REACTIVADA',
            'data'    => $farm->fresh()->load('thirdParty'),
        ]);
    }
}