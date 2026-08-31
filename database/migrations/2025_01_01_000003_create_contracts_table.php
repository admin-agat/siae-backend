<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * contracts
     * Links a third_party (typically a producer) to a contracted volume and harvest day.
     */
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('third_party_id')
                ->constrained('third_parties')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('magap_contract_number')->nullable(); // e.g. "925"
            $table->decimal('contracted_quantity', 12, 2)->default(0); // e.g. 20000 boxes
            $table->string('harvest_day')->nullable(); // e.g. "Saturday"
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->index(['third_party_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};