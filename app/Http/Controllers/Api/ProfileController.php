<?php

// PERFILES DE PERMISOS (CONFIGURACIÓN, solo ADMIN) — 30/10/2026
// Ubicación: app/Http/Controllers/Api/ProfileController.php
//
// Un perfil = nombre (ej. ASISTENTE COMERCIAL) + tipo base + pantallas que ve. El tipo base conserva
// las reglas que dependen del rol (el BODEGUERO opera solo su bodega, el COORDINADOR corrige
// movimientos...). El perfil ADMINISTRADOR tiene todo y no se edita. Nunca se borran: se desactivan.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    // Catálogo de pantallas en el orden del menú: [{ key, seccion, nombre }]
    public function screens()
    {
        return response()->json(collect(config('pantallas'))
            ->map(fn ($p, $key) => ['key' => $key, 'seccion' => $p['seccion'], 'nombre' => $p['nombre']])
            ->values());
    }

    public function index()
    {
        return response()->json(Profile::withCount(['users' => fn ($q) => $q->where('status', true)])->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $this->validar($request);
        $perfil = Profile::create($data + ['status' => true]);

        return response()->json($perfil, 201);
    }

    public function update(Request $request, $id)
    {
        $perfil = Profile::findOrFail($id);
        if ($perfil->base_role === 'ADMIN') {
            return response()->json(['message' => 'EL PERFIL ADMINISTRADOR TIENE TODO EL SISTEMA Y NO SE EDITA.'], 422);
        }
        $data = $this->validar($request, $perfil);
        // Cambiar el tipo base con usuarios dentro dejaría bodegueros sin bodega (o al revés)
        if ($data['base_role'] !== $perfil->base_role && $perfil->users()->exists()) {
            throw ValidationException::withMessages(['base_role' => 'NO SE PUEDE CAMBIAR EL TIPO BASE DE UN PERFIL QUE YA TIENE USUARIOS. CREA OTRO PERFIL.']);
        }
        $perfil->update($data);

        return response()->json($perfil);
    }

    public function deactivate($id)
    {
        $perfil = Profile::withCount(['users' => fn ($q) => $q->where('status', true)])->findOrFail($id);
        if ($perfil->base_role === 'ADMIN') {
            return response()->json(['message' => 'EL PERFIL ADMINISTRADOR NO SE DESACTIVA.'], 422);
        }
        if ($perfil->users_count > 0) {
            return response()->json(['message' => "EL PERFIL TIENE {$perfil->users_count} USUARIO(S) ACTIVO(S). CÁMBIALOS DE PERFIL ANTES DE DESACTIVARLO."], 422);
        }
        $perfil->update(['status' => false]);

        return response()->json($perfil);
    }

    public function reactivate($id)
    {
        $perfil = Profile::findOrFail($id);
        $perfil->update(['status' => true]);

        return response()->json($perfil);
    }

    private function validar(Request $request, ?Profile $perfil = null): array
    {
        $request->merge(['name' => mb_strtoupper(trim((string) $request->input('name')))]);
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100', Rule::unique('profiles', 'name')->ignore($perfil?->id)],
            // El tipo ADMIN no se reparte: solo existe el perfil ADMINISTRADOR
            'base_role'   => ['required', Rule::in(array_diff(Profile::TIPOS_BASE, ['ADMIN']))],
            'description' => 'nullable|string|max:255',
            'screens'     => 'present|array',
            'screens.*'   => ['string', Rule::in(array_keys(config('pantallas')))],
        ], [
            'name.unique'      => 'YA EXISTE UN PERFIL CON ESE NOMBRE.',
            'base_role.in'     => 'ELIGE EL TIPO BASE DEL PERFIL.',
            'screens.*.in'     => 'HAY UNA PANTALLA QUE NO EXISTE.',
        ]);
        $data['screens'] = array_values(array_unique($data['screens']));

        return $data;
    }
}
