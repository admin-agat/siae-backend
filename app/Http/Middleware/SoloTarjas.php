<?php

// 17/11/2026 — El TARJADOR (persona del puerto) solo llena tarjas y no ve precios ni montos. Lista blanca:
// fuera de sus datos, cambiar la contraseña, cerrar sesión y Tarjas, la API le responde 403. Así tampoco
// lee las rutas de lectura abierta (terceros, marcas, precios MAG, insumos...) ni las que se agreguen después.
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SoloTarjas
{
    private const PERMITIDAS = ['api/me', 'api/me/password', 'api/logout', 'api/tallies', 'api/tallies/*', 'api/customer-orders/*/tallies'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role === 'TARJADOR' && !$request->is(...self::PERMITIDAS)) {
            \App\Models\AuditLog::registrarDenegado('TARJADOR'); // 17/11/2026
            return response()->json(['message' => 'TU PERFIL SOLO PUEDE USAR TARJAS.'], 403);
        }

        return $next($request);
    }
}
