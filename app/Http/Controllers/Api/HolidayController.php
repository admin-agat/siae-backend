<?php

// FERIADOS — 16/11/2026, vacaciones fase 1
// Ubicación: app/Http/Controllers/Api/HolidayController.php
// LEGAL los carga cada año con el día YA TRASLADADO por decreto. Sirven para contar días hábiles en
// los permisos (fase 2) y se pintan en el calendario (fase 3). Rutas con pantalla:empleados_saldos.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HolidayController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(Holiday::query()
            ->when($request->filled('year'), fn ($q) => $q->whereYear('holiday_date', (int) $request->year))
            ->when($request->has('status'), fn ($q) => $q->where('status', $request->boolean('status')))
            ->orderBy('holiday_date')->get());
    }

    public function store(Request $request)
    {
        $datos = $this->validar($request);
        $this->unicoActivo($datos['holiday_date']);

        return response()->json(Holiday::create($datos + ['status' => true]), 201);
    }

    public function update(Request $request, $id)
    {
        $h = Holiday::findOrFail($id);
        $datos = $this->validar($request);
        if ($h->status) {
            $this->unicoActivo($datos['holiday_date'], $h->id);
        }
        $h->update($datos);

        return response()->json($h);
    }

    public function deactivate($id)
    {
        $h = Holiday::findOrFail($id);
        $h->update(['status' => false]);

        return response()->json($h);
    }

    public function reactivate($id)
    {
        $h = Holiday::findOrFail($id);
        $this->unicoActivo($h->holiday_date->format('Y-m-d'), $h->id);
        $h->update(['status' => true]);

        return response()->json($h);
    }

    private function validar(Request $request): array
    {
        $request->merge(['name' => mb_strtoupper(trim((string) $request->input('name')))]);

        return $request->validate([
            'holiday_date' => 'required|date',
            'name'         => 'required|string|min:3|max:100',
            'scope'        => ['required', Rule::in(Holiday::SCOPES)],
        ], [
            'holiday_date.*' => 'INDICA LA FECHA DEL FERIADO.',
            'name.*'         => 'ESCRIBE EL NOMBRE DEL FERIADO (HASTA 100 LETRAS).',
            'scope.*'        => 'ELIGE SI ES NACIONAL O LOCAL.',
        ]);
    }

    private function unicoActivo(string $fecha, ?int $excepto = null): void
    {
        $existe = Holiday::where('status', true)->whereDate('holiday_date', $fecha)
            ->when($excepto, fn ($q) => $q->whereKeyNot($excepto))->exists();
        if ($existe) {
            throw ValidationException::withMessages(['holiday_date' => 'YA HAY UN FERIADO ACTIVO ESE DÍA.']);
        }
    }
}
