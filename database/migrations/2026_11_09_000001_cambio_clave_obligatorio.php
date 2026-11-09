<?php

// 09/11/2026 — LOPDP: cambio de contraseña obligatorio.
//  - must_change_password: el usuario debe cambiarla al entrar (usuario nuevo o contraseña puesta por el ADMIN).
//  - password_changed_at: cuándo la cambió él mismo; vence a los 3 meses (User::MESES_VIGENCIA_CLAVE).
// Los usuarios que ya existen quedan marcados (todas sus contraseñas las puso el ADMIN). El ADMIN queda fuera.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
        });

        DB::table('users')->where('role', '<>', 'ADMIN')->update(['must_change_password' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'password_changed_at']);
        });
    }
};
