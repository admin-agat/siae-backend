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
        Sanctum::authenticateAccessTokensUsing(fn ($token, bool $valido) => $valido && (bool) $token->tokenable?->status);

        // 02/11/2026: límite de intentos de login por usuario + IP (5/min) y por IP (30/min). Antes era 5/min
        // solo por IP: si la oficina sale a internet por una sola IP, la 6.ª persona del minuto quedaba bloqueada.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower(trim((string) $request->input('email'))) . '|' . $request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
    }
}
