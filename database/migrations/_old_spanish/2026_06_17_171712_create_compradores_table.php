<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {

        \Illuminate\Support\Facades\DB::statement('CREATE SCHEMA IF NOT EXISTS comercial_internacional');

        Schema::create('comercial_internacional.compradores', function (Blueprint $table) {
            $table->id();
            $table->string('razon_social');
            $table->string('pais');
            $table->string('ciudad')->nullable();
            $table->string('contacto_nombre')->nullable();
            $table->string('contacto_email')->nullable();
            $table->string('contacto_telefono')->nullable();
            $table->enum('tipo', ['contractual','spot'])->default('spot');
            $table->string('moneda')->default('USD');
            $table->string('condicion_pago')->nullable(); //dias de pago
            $table->boolean('activo')->default(true); // Estado Activo/inactivo
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
         Schema::dropIfExists('comercial_internacional.compradores');
    }
};
