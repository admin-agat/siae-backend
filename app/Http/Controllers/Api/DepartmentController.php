<?php

// DEPARTAMENTOS (catálogo) — 16/11/2026, vacaciones fase 1 (P13: se elige con búsqueda por nombre)
// Ubicación: app/Http/Controllers/Api/DepartmentController.php
// Rutas con pantalla:empleados_saldos. Soft-delete con status (PATCH deactivate / reactivate).

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(Department::withCount(['employees' => fn ($q) => $q->where('status', true)])
            ->when($request->has('status'), fn ($q) => $q->where('status', $request->boolean('status')))
            ->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $dep = Department::create($this->validar($request) + ['status' => true]);

        return response()->json($dep, 201);
    }

    public function update(Request $request, $id)
    {
        $dep = Department::findOrFail($id);
        $dep->update($this->validar($request, $dep));

        return response()->json($dep);
    }

    public function deactivate($id)
    {
        $dep = Department::findOrFail($id);
        $dep->update(['status' => false]);

        return response()->json($dep);
    }

    public function reactivate($id)
    {
        $dep = Department::findOrFail($id);
        $dep->update(['status' => true]);

        return response()->json($dep);
    }

    private function validar(Request $request, ?Department $actual = null): array
    {
        $request->merge(['name' => mb_strtoupper(trim((string) $request->input('name')))]);

        return $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:60', Rule::unique('departments', 'name')->ignore($actual?->id)],
        ], [
            'name.unique' => 'ESE DEPARTAMENTO YA EXISTE.',
            'name.*'      => 'ESCRIBE EL NOMBRE DEL DEPARTAMENTO (HASTA 60 LETRAS).',
        ]);
    }
}
