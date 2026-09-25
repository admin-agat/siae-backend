<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\MaterialRecipe;
use App\Models\Supply;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaterialRecipeController extends Controller
{
    /**
     * Marcas válidas = códigos de las marcas ACTIVAS en la tabla brands.
     * Cada marca tiene su receta completa (sin recetas compartidas).
     */
    private function marcasValidas(): array
    {
        return Brand::where('status', true)->pluck('code')->all();
    }

    /**
     * Reglas compartidas entre store y update.
     * Unicidad (supply_id, brand): un insumo no se repite en la misma marca,
     * pero sí puede estar en varias marcas (ej. FUNDA SIN LOGO, químicos).
     */
    private function reglas(Request $request, ?int $ignorarId = null): array
    {
        $unicoPorMarca = Rule::unique('material_recipes', 'supply_id')
            ->where(fn ($q) => $q->where('brand', $request->input('brand')));

        if ($ignorarId) {
            $unicoPorMarca->ignore($ignorarId);
        }

        return [
            'supply_id'     => ['required', 'exists:supplies,id', $unicoPorMarca],
            'brand'         => ['required', 'string', Rule::in($this->marcasValidas())],
            'ratio_per_box' => ['required', 'numeric', 'min:0'],
            'unit'          => ['required', 'string', 'max:20'],
        ];
    }

    /**
     * Lista las recetas. Con ?brand=GLOBAL_VILLAGE filtra solo esa marca.
     * GET /api/material-recipes?brand=GLOBAL_VILLAGE
     */
    public function index(Request $request)
    {
        $query = MaterialRecipe::with('supply.category')->orderBy('brand')->orderBy('id');

        if ($request->filled('brand')) {
            $query->where('brand', $request->input('brand'));
        }

        return response()->json($query->get());
    }

    /**
     * TODOS los insumos activos, cada uno con 'bloqueo':
     * null = se puede asignar; texto = ya tiene receta en esta marca.
     * GET /api/material-recipes/available-supplies?brand=PALMS_BANANA
     */
    public function availableSupplies(Request $request)
    {
        $brand = $request->input('brand');

        // Insumos que ya tienen receta en esta marca (activa o inactiva: el UNIQUE las cuenta a ambas)
        $yaEnMarca = MaterialRecipe::where('brand', $brand)->pluck('supply_id')->flip();

        $insumos = Supply::where('status', true)
            ->orderBy('name')
            ->get(['id', 'name', 'unit'])
            ->map(fn ($insumo) => [
                'id'      => $insumo->id,
                'name'    => $insumo->name,
                'unit'    => $insumo->unit,
                'bloqueo' => $yaEnMarca->has($insumo->id) ? 'YA ESTÁ EN ESTA MARCA' : null,
            ]);

        return response()->json($insumos->values());
    }

    /**
     * Crea una nueva receta.
     * POST /api/material-recipes
     */
    public function store(Request $request)
    {
        $validated = $request->validate($this->reglas($request));

        $validated['base_cupo'] = 'MARCA'; // siempre sobre el cupo propio de la marca
        $validated['status']    = true;    // toda receta nueva arranca activa

        $receta = MaterialRecipe::create($validated);
        $receta->load('supply.category');

        return response()->json($receta, 201);
    }

    /**
     * Actualiza una receta existente.
     * PUT /api/material-recipes/{id}
     */
    public function update(Request $request, $id)
    {
        $receta = MaterialRecipe::findOrFail($id);

        $validated = $request->validate($this->reglas($request, $receta->id));
        $validated['base_cupo'] = 'MARCA';

        $receta->update($validated);
        $receta->load('supply.category');

        return response()->json($receta);
    }

    /**
     * Desactiva una receta (soft delete lógico, nunca se borra físicamente).
     * PATCH /api/material-recipes/{id}/deactivate
     */
    public function deactivate($id)
    {
        $receta = MaterialRecipe::findOrFail($id);
        $receta->update(['status' => false]);

        return response()->json($receta);
    }

    /**
     * Reactiva una receta previamente desactivada.
     * PATCH /api/material-recipes/{id}/reactivate
     */
    public function reactivate($id)
    {
        $receta = MaterialRecipe::findOrFail($id);
        $receta->update(['status' => true]);

        return response()->json($receta);
    }
}