<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * farms
     * Fincas asociadas a un productor (third_party). Un productor puede
     * tener varias fincas. Incluye el código MAGAP y la zona propia
     * de cada finca (puede diferir entre fincas del mismo productor).
     */
    public function up(): void
    {
        Schema::create('farms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('third_party_id')
                ->constrained('third_parties')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('name');
            $table->string('magap_code')->nullable(); // e.g. "09815" o "NO CONSTA"
            $table->string('zone')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->index(['third_party_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farms');
    }
};