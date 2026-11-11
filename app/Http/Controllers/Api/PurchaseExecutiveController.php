<?php

// 11/11/2026: lista de comerciales que compran la fruta — solo ADMIN agrega, desactiva o reactiva.
// Compra de fruta la recibe en GET /customer-orders/{id}/purchases (executives).
//   GET   /purchase-executives                  todos (activos e inactivos)
//   POST  /purchase-executives                  agregar
//   PATCH /purchase-executives/{id}/deactivate  desactivar (las compras que ya lo tienen no cambian)
//   PATCH /purchase-executives/{id}/reactivate  reactivar

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseExecutive;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PurchaseExecutiveController extends Controller
{
    public function index()
    {
        return response()->json(PurchaseExecutive::orderByDesc('status')->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:60'], ['name.required' => 'ESCRIBE EL NOMBRE DEL COMERCIAL.']);
        // Mayúsculas y sin "/", que es el separador cuando una compra tiene varios comerciales
        $nombre = preg_replace('/\s+/', ' ', trim(mb_strtoupper(str_replace('/', ' ', $data['name']))));
        if (PurchaseExecutive::whereRaw('UPPER(name) = ?', [$nombre])->exists()) {
            throw ValidationException::withMessages(['name' => "$nombre YA ESTÁ EN LA LISTA (SI ESTÁ DESACTIVADO, REACTÍVALO)."]);
        }

        return response()->json(PurchaseExecutive::create(['name' => $nombre, 'status' => true]), 201);
    }

    public function deactivate($id)
    {
        $comercial = PurchaseExecutive::findOrFail($id);
        $comercial->update(['status' => false]);

        return response()->json($comercial);
    }

    public function reactivate($id)
    {
        $comercial = PurchaseExecutive::findOrFail($id);
        $comercial->update(['status' => true]);

        return response()->json($comercial);
    }
}
