<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    //
    protected $connect = 'pgsql';



    public function up(): void
    {

        //sino esta lo creamos
        DB::statement('CREATE SCHEMA IF NOT EXISTS comercial_internacional');
        
        Schema::create('comercial_internacional.marcas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre,100'); // van dona elena, global village
            $table->boolean('activo')->default(true); // estado activo/inactivo
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comercial_internacional.marcas');
    }
};
