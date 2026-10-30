<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermitirPantallas
{
    /**
     * 30/10/2026: permisos por persona. Deja pasar si el usuario tiene AL MENOS UNA de las pantallas
     * indicadas (claves de config/pantallas.php). El ADMIN pasa siempre. Uso en routes/api.php:
     *   ->middleware('pantalla:compra_fruta')
     */
    public function handle(Request $request, Closure $next, string ...$pantallas): Response
    {
        if (!$request->user() || !$request->user()->puede(...$pantallas)) {
            return response()->json([
                'message' => 'No tienes permiso para realizar esta acción.',
            ], 403);
        }

        return $next($request);
    }
}
