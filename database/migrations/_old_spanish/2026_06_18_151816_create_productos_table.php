<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    protected $connected = 'pgsql';


    public function up(): void
    {
        Schema::create('comercial_internacional.productos', function (Blueprint $table) {
            $table->id();

            $table->string('codigo',50)->unique();
            $table->string('nombre',150);

            // Relación con la tabla de marcas
            $table->foreignId('marca_id')
                  ->constrained('comercial_internacional.marcas')
                  ->noActionOnDelete(); // No permite borrar marca si tiene productos

            $table->string('tipo_caja',20)->default('22UX');
            
            $table->decimal('peso_kg', 5,2);

            $table->boolean('activo')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comercial_internacional.productos');
    }
};