<?php

// 09/11/2026 — LOPDP: mientras el usuario deba cambiar su contraseña (primer ingreso o vencida a los
// 3 meses) la API solo le deja ver sus datos, cambiarla y cerrar sesión. Así no se salta la pantalla
// "Cambia tu contraseña" llamando a la API directo.
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ClaveVigente
{
    private const PERMITIDAS = ['api/me', 'api/me/password', 'api/logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $motivo = $request->user()?->motivoCambioClave();
        if ($motivo && !$request->is(...self::PERMITIDAS)) {
            return response()->json([
                'message' => 'DEBES CAMBIAR TU CONTRASEÑA ANTES DE CONTINUAR.',
                'codigo'  => 'CAMBIAR_CLAVE',
                'motivo'  => $motivo,
            ], 403);
        }

        return $next($request);
    }
}
