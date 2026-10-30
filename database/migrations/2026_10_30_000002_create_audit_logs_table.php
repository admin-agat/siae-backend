<?php

// BITÁCORA DE AUDITORÍA (30/10/2026)
// Queda registrado lo que cada usuario hace en el sistema, con fecha y hora: crear, editar,
// desactivar/reactivar y borrar cualquier registro (con los valores antes y después), además del
// inicio y cierre de sesión. La escribe el trait App\Models\Concerns\Auditable; nunca se edita ni se borra.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users'); // null = proceso del sistema
            $table->string('user_name', 255)->nullable(); // nombre al momento de la acción
            $table->string('action', 20);                 // CREAR / EDITAR / DESACTIVAR / REACTIVAR / BORRAR / INICIO_SESION / CIERRE_SESION
            $table->string('table_name', 60)->nullable(); // tabla afectada (ej. producer_quota_lines)
            $table->unsignedBigInteger('record_id')->nullable();
            $table->jsonb('changes')->nullable();          // { campo: [antes, después] } o los valores al crear/borrar
            $table->string('ip', 45)->nullable();
            $table->string('route', 255)->nullable();      // método + ruta de la API (PUT api/customer-orders/4/purchases)
            $table->timestamp('created_at')->useCurrent();

            $table->index(['table_name', 'record_id']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
