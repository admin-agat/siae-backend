<?php
// Controlador para gestionar compradores internacionales

namespace App\Http\Controllers;

use App\Models\Comprador;
use Illuminate\Http\Request;

class CompradorController extends Controller
{
    // GET /api/compradores — listar todos los compradores
    public function index()
    {
        $compradores = Comprador::orderBy('razon_social')->get();
        return response()->json($compradores);
    }

    // POST /api/compradores — crear un nuevo comprador
    public function store(Request $request)
    {
        $request->validate([
            'razon_social' => 'required|string|max:255',
            'pais'         => 'required|string|max:100',
            'tipo'         => 'required|in:contractual,spot',
        ]);

        $comprador = Comprador::create($request->all());
        return response()->json($comprador, 201);
    }

    // GET /api/compradores/{id} — ver un comprador específico
    public function show($id)
    {
        $comprador = Comprador::findOrFail($id);
        return response()->json($comprador);
    }

    // PUT /api/compradores/{id} — actualizar un comprador
    public function update(Request $request, $id)
    {
        $comprador = Comprador::findOrFail($id);

        $request->validate([
            'razon_social' => 'sometimes|required|string|max:255',
            'pais'         => 'sometimes|required|string|max:100',
            'tipo'         => 'sometimes|required|in:contractual,spot',
        ]);

        $comprador->update($request->all());
        return response()->json($comprador);
    }

    // DELETE /api/compradores/{id} — eliminar un comprador
    public function destroy($id)
    {
        $comprador = Comprador::findOrFail($id);
        $comprador->delete();
        return response()->json(['message' => 'Comprador eliminado correctamente']);
    }
}