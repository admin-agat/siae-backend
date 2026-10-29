<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermitirRoles
{
    /**
     * Deja pasar solo a los roles indicados en la ruta. Uso en routes/api.php:
     *   ->middleware('rol:ADMIN,COORDINADOR_INVENTARIO')
     * Protege la API aunque alguien la llame directo, sin pasar por el frontend
     * (el frontend también oculta las pantallas, pero eso solo es visual).
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!$request->user() || !in_array($request->user()->role, $roles, true)) {
            return response()->json([
                'message' => 'No tienes permiso para realizar esta acción.',
            ], 403);
        }

        return $next($request);
    }
}
