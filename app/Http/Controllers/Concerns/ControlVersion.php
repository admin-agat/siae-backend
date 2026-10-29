<?php

// Control de cambios simultáneos para las hojas que se guardan completas (29/10/2026).
//
// Distribución en barcos, Compra de fruta y Cierre de embarque guardan TODA la semana de una
// vez. Con varios usuarios (las compras se cargan durante la semana), si dos personas abren la
// hoja y guardan, la segunda borraría lo que guardó la primera. Cada GET entrega una "version"
// (huella de lo guardado) y el PUT debe devolverla: si alguien guardó antes, responde 409.

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

trait ControlVersion
{
    // $consultas: query builders de las tablas de la hoja (ya filtradas por la semana)
    protected function huella(array $consultas): string
    {
        $partes = [];
        foreach ($consultas as $q) {
            $fila = (clone $q)->selectRaw('COUNT(*) AS n, MAX(updated_at) AS u')->first();
            $partes[] = $fila->n . '@' . $fila->u;
        }

        return md5(implode('|', $partes));
    }

    protected function verificarVersion(Request $request, string $actual): void
    {
        $request->validate(['version' => 'required|string']);
        if ($request->input('version') !== $actual) {
            throw new HttpResponseException(response()->json([
                'message' => 'OTRO USUARIO GUARDÓ CAMBIOS EN ESTA HOJA MIENTRAS LA EDITABAS. RECARGA LA PÁGINA PARA VER LO ÚLTIMO Y VUELVE A HACER TUS CAMBIOS.',
                'conflict' => true,
            ], 409));
        }
    }
}
