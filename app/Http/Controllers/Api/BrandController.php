<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BrandController extends Controller
{
    /**
     * Lista todas las marcas (activas e inactivas; la página filtra).
     * GET /api/brands
     */
    public function index()
    {
        return response()->json(Brand::orderBy('name')->get());
    }

    /**
     * Crea una nueva marca.
     * POST /api/brands
     */
    public function store(Request $request)
    {
        // Primero se normaliza (mayúsculas, guion bajo) y DESPUÉS se valida,
        // para que el unique compare contra el mismo texto que se va a guardar.
        $this->normalizar($request);
        $validated = $request->validate($this->reglas(), $this->mensajes());

        $validated['status'] = true;

        $brand = Brand::create($validated);

        return response()->json($brand, 201);
    }

    /**
     * Actualiza una marca existente.
     * PUT /api/brands/{id}
     *
     * El code NO es un campo cualquiera: material_recipes.brand y
     * producer_quota_lines.variety guardan el TEXTO del código (no el id).
     * Por eso:
     *  - Si la marca ya tiene cupos asignados, el code queda bloqueado
     *    (solo se puede corregir el nombre).
     *  - Si solo tiene recetas, se renombran en la misma transacción
     *    para que no queden huérfanas.
     */
    public function update(Request $request, $id)
    {
        $brand = Brand::findOrFail($id);

        $this->normalizar($request);
        $validated = $request->validate($this->reglas($brand->id), $this->mensajes());

        $codigoAnterior = $brand->code;
        $cambiaCodigo = $validated['code'] !== $codigoAnterior;

        // Bloqueo: el código ya fue usado en cupos (documentos históricos)
        if ($cambiaCodigo && DB::table('producer_quota_lines')->where('variety', $codigoAnterior)->exists()) {
            return response()->json([
                'message' => 'NO SE PUEDE CAMBIAR EL CÓDIGO: LA MARCA YA TIENE CUPOS ASIGNADOS. SOLO PUEDES CORREGIR EL NOMBRE.',
            ], 422);
        }

        DB::transaction(function () use ($brand, $validated, $cambiaCodigo, $codigoAnterior) {
            // Arrastra las recetas al nuevo código antes de actualizar la marca
            if ($cambiaCodigo) {
                DB::table('material_recipes')
                    ->where('brand', $codigoAnterior)
                    ->update(['brand' => $validated['code']]);
            }

            $brand->update($validated);
        });

        return response()->json($brand->fresh());
    }

    /**
     * Desactiva una marca (soft delete lógico).
     * PATCH /api/brands/{id}/deactivate
     */
    public function deactivate($id)
    {
        $brand = Brand::findOrFail($id);
        $brand->update(['status' => false]);

        return response()->json($brand->fresh());
    }

    /**
     * Reactiva una marca previamente desactivada.
     * PATCH /api/brands/{id}/reactivate
     */
    public function reactivate($id)
    {
        $brand = Brand::findOrFail($id);
        $brand->update(['status' => true]);

        return response()->json($brand->fresh());
    }

    /**
     * Normaliza name y code dentro del request.
     * mb_strtoupper (no strtoupper) para que "doña" quede "DOÑA" y no "DOñA".
     * Los espacios del code (uno o varios) se convierten en un solo guion bajo.
     */
    private function normalizar(Request $request): void
    {
        $request->merge([
            'name' => mb_strtoupper(trim((string) $request->input('name')), 'UTF-8'),
            'code' => mb_strtoupper(preg_replace('/\s+/', '_', trim((string) $request->input('code'))), 'UTF-8'),
        ]);
    }

    /**
     * Reglas compartidas por store y update.
     * $ignorarId = id de la marca que se edita (para que no choque consigo misma).
     */
    private function reglas(?int $ignorarId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('brands', 'name')->ignore($ignorarId)],
            // Solo letras mayúsculas, Ñ, números y guion bajo (ej. DOÑA_ELENA)
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9Ñ_]+$/u', Rule::unique('brands', 'code')->ignore($ignorarId)],
        ];
    }

    /**
     * Mensajes en mayúsculas para que el modal los muestre tal cual.
     */
    private function mensajes(): array
    {
        return [
            'name.required' => 'EL NOMBRE ES OBLIGATORIO.',
            'name.unique'   => 'YA EXISTE UNA MARCA CON ESE NOMBRE.',
            'code.required' => 'EL CÓDIGO ES OBLIGATORIO.',
            'code.unique'   => 'YA EXISTE UNA MARCA CON ESE CÓDIGO.',
            'code.regex'    => 'EL CÓDIGO SOLO ADMITE LETRAS, NÚMEROS Y GUION BAJO.',
            'code.max'      => 'EL CÓDIGO NO PUEDE SUPERAR 30 CARACTERES.',
        ];
    }
}