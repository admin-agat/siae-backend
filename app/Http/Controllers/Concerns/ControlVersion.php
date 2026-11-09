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
use Illuminate\Support\Facades\DB;

trait ControlVersion
{
    // $consultas: query builders de las tablas de la hoja (ya filtradas por la semana), con columna id.
    // 09/11/2026: la huella es el CONTENIDO de las filas (md5 de todas, ordenadas por id), ya no
    // COUNT + MAX(updated_at): updated_at va en segundos y dos guardados en el mismo segundo daban
    // la misma huella (el segundo no recibía el 409).
    protected function huella(array $consultas): string
    {
        $partes = [];
        foreach ($consultas as $q) {
            $fila = DB::query()->fromSub(clone $q, 't')
                ->selectRaw("COUNT(*) AS n, md5(COALESCE(string_agg(t::text, '|' ORDER BY t.id), '')) AS h")
                ->first();
            $partes[] = $fila->n . '@' . $fila->h;
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
