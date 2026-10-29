<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CabecerasSeguridad
{
    /**
     * Cabeceras HTTP de seguridad en todas las respuestas de la API:
     *  - nosniff: el navegador no "adivina" el tipo de archivo (evita ejecutar JSON como script)
     *  - DENY: la API no se puede incrustar en un iframe (clickjacking)
     *  - no-referrer: no se filtran URLs internas a otros sitios
     *  - no-store: los datos (precios, stock) no quedan guardados en la caché del navegador
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
