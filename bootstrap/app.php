<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Permitir peticiones desde el frontend React
        $middleware->validateCsrfTokens(except: ['api/*']);
        
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Cabeceras de seguridad (nosniff, sin iframe, sin caché) en toda la API
        $middleware->api(append: [
            \App\Http\Middleware\CabecerasSeguridad::class,
        ]);

        // 29/10/2026: sin sesión, la API responde 401 (no redirige a una ruta "login" inexistente)
        $middleware->redirectGuestsTo(fn ($request) => $request->is('api/*') ? null : '/');

        // Alias 'admin' para restringir rutas solo al rol ADMIN (Terceros, Fincas)
        // Alias 'rol' para restringir por lista de roles: ->middleware('rol:ADMIN,JEFE_BODEGA')
        $middleware->alias([
            'admin' => \App\Http\Middleware\SoloAdmin::class,
            'rol' => \App\Http\Middleware\PermitirRoles::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 29/10/2026: la API siempre responde JSON (sin la cabecera Accept, una petición sin
        // sesión intentaba redirigir a una ruta "login" que no existe y daba 500 en vez de 401)
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*'));
    })->create();