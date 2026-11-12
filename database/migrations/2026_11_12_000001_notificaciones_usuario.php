<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 12/11/2026 (diseño aprobado por Peter): campanita de notificaciones por persona. El primer aviso es
// ENVIO_BODEGA: Comercial envió a retirar una compra (le llega al bodeguero de esa bodega y a los
// coordinadores de inventario). La tabla no se llama "notifications" porque User usa el trait Notifiable
// de Laravel, que ya reserva ese nombre.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('type', 30);                 // ENVIO_BODEGA (luego otros avisos)
            $table->string('title', 150);
            $table->string('body', 500);
            $table->string('link', 255)->nullable();    // ruta del frontend que abre al hacer clic
            $table->json('data')->nullable();           // lo que esa pantalla necesita (bodega, vendedor, cupo, marca)
            $table->foreignId('producer_quota_line_id')->nullable()->constrained('producer_quota_lines');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
            // sin índice único: si Comercial quita el envío y lo vuelve a enviar, se avisa otra vez
            // (enviar dos veces seguidas no repite: enviarABodega salta las compras ya enviadas)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
