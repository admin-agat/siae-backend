<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SoloAdmin
{
    /**
     * Bloquea el acceso si el usuario autenticado no tiene rol ADMIN.
     * Se usa para proteger módulos que JEFE_BODEGA y COORDINADOR_INVENTARIO
     * no deben poder ver ni modificar (ej. Terceros, Fincas), incluso si
     * intentan llamar la API directamente sin pasar por el frontend.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() || !$request->user()->esAdmin()) {
            return response()->json([
                'message' => 'No tienes permiso para acceder a este módulo.',
            ], 403);
        }

        return $next($request);
    }
}