<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 02/11/2026 (revisión de seguridad): un token solo vale si su usuario sigue activo. Antes, un usuario
        // desactivado por fuera de la pantalla Usuarios (que sí borra sus tokens) seguía entrando hasta 12 h.
        // 05/11/2026: tampoco vale si lleva más de sanctum.inactividad minutos sin usarse (cierre por inactividad).
        Sanctum::authenticateAccessTokensUsing(function ($token, bool $valido) {
            $inactividad = (int) config('sanctum.inactividad');
            $ultimoUso = $token->last_used_at ?? $token->created_at;

            return $valido
                && (bool) $token->tokenable?->status
                && (!$inactividad || $ultimoUso->gt(now()->subMinutes($inactividad)));
        });

        // 02/11/2026: límite de intentos de login por usuario + IP (5/min) y por IP (30/min). Antes era 5/min
        // solo por IP: si la oficina sale a internet por una sola IP, la 6.ª persona del minuto quedaba bloqueada.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower(trim((string) $request->input('email'))) . '|' . $request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);

        // 09/11/2026: cambiar la propia contraseña pide la actual → 10 intentos por minuto por usuario.
        // Con nombre propio: un 'throttle:10,1' suelto compartía el contador con el 'throttle:120,1' general.
        RateLimiter::for('cambio_clave', fn (Request $request) => Limit::perMinute(10)->by('clave|' . $request->user()?->id));
    }
}
