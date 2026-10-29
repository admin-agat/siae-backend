<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Borra cada día los tokens de sesión vencidos (> 12 h, ver config/sanctum.php 'expiration')
// para que la tabla personal_access_tokens no crezca sin límite.
// Requiere el programador de tareas: `php artisan schedule:run` cada minuto (cron en producción).
Schedule::command('sanctum:prune-expired --hours=24')->daily();
