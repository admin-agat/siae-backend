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

// 16/11/2026: vacaciones — acredita cada día los 15 días (+ adicionales) de quien cumplió un año más de
// servicio. Va de uno en uno para que quede en la bitácora. El saldo también acredita al consultarse,
// por si el programador de tareas no corrió.
Artisan::command('vacaciones:acreditar', function (App\Services\SaldoVacaciones $saldos) {
    $total = 0;
    App\Models\Employee::where('status', true)->orderBy('id')->each(function ($e) use ($saldos, &$total) {
        $total += $saldos->acreditarPendientes($e);
    });
    $this->info("ACREDITADOS: {$total}");
})->purpose('Acredita los días de vacaciones ganados por aniversario');
Schedule::command('vacaciones:acreditar')->dailyAt('06:00')->timezone('America/Guayaquil');
