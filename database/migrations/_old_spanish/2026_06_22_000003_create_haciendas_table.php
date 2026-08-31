<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `haciendas` es uno a muchos con personas: un Productor puede tener
     * varias haciendas. El nombre de la hacienda puede repetirse entre
     * distintos productores o incluso dentro del mismo productor
     * (ej. "Homerita" en Machala y en Guayaquil), por eso nunca se
     * identifica por nombre, sino por id + zona.
     */
    public function up(): void
    {
        Schema::create('terceros.haciendas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('persona_id')
                ->comment('Productor dueño de esta hacienda')
                ->constrained('terceros.personas')
                ->cascadeOnDelete();

            $table->string('nombre', 150);
            $table->string('zona', 100)
                ->comment('Ej: ZONA MACHALA, BODEGA PRINCIPAL, SAN JUAN');

            $table->boolean('activo')->default(true);

            $table->timestamps();

            // Una misma persona no debería repetir hacienda+zona exactamente igual
            $table->unique(['persona_id', 'nombre', 'zona']);
            $table->index('zona');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terceros.haciendas');
    }
};
