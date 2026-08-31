<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\SupplyCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplyCategoryController extends Controller
{
    // Opciones fijas de grupo — catálogo estático, no tabla
    const GRUPOS = ['CARTON', 'EMPAQUE', 'CONTENEDOR'];

    public function index(Request $request)
    {
        $query = SupplyCategory::where('status', true);

        // Filtro opcional: /supply-categories?group_label=CARTON
        if ($request->filled('group_label')) {
            $query->where('group_label', $request->group_label);
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('supply_categories')],
            'group_label' => ['required', Rule::in(self::GRUPOS)],
            'chargeable_to_producer' => 'required|boolean',
        ]);

        $category = SupplyCategory::create($validated);
        return response()->json($category, 201);
    }

    public function show($id)
    {
        return response()->json(SupplyCategory::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $category = SupplyCategory::findOrFail($id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('supply_categories')->ignore($category->id)],
            'group_label' => ['required', Rule::in(self::GRUPOS)],
            'chargeable_to_producer' => 'required|boolean',
        ]);

        $category->update($validated);
        return response()->json($category);
    }

    public function deactivate($id)
    {
        $category = SupplyCategory::findOrFail($id);
        $category->update(['status' => false]);
        return response()->json(['message' => 'Categoría desactivada correctamente']);
    }

    public function reactivate($id)
    {
        $category = SupplyCategory::findOrFail($id);
        $category->update(['status' => true]);
        return response()->json(['message' => 'Categoría reactivada correctamente']);
    }

    // Devuelve el próximo código disponible para una categoría (ej: CRT-010)
    public function nextCode($id)
    {
        $category = SupplyCategory::findOrFail($id);

        if (!$category->code_prefix) {
            return response()->json(['code' => null]);
        }

        // Cuenta cuántos insumos ya existen en esa categoría (incluyendo inactivos, para no repetir números)
        $count = \App\Models\Supply::where('supply_category_id', $id)->count();
        $siguienteNumero = str_pad($count + 1, 3, '0', STR_PAD_LEFT);

        return response()->json(['code' => $category->code_prefix . '-' . $siguienteNumero]);
    }
}