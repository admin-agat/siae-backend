<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // ej. "EMB-00000096", generado en el backend
            $table->date('registration_date');
            $table->date('departure_date');
            $table->string('vessel_name'); // "Nombre" - buque + viaje
            $table->foreignId('shipping_line_id')->constrained('shipping_lines');
            $table->unsignedSmallInteger('week_start');
            $table->unsignedSmallInteger('week_end');
            $table->unsignedSmallInteger('year');
            $table->text('comment')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->boolean('status')->default(true); // true = ACTIVO, false = ANULADO
            $table->timestamps();

            $table->index(['year', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
