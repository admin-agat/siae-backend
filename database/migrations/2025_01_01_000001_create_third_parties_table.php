<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * third_parties
     * Base entity for Producer, Supplier and Customer (Party Pattern).
     * Extended by: customers, contracts.
     */
    public function up(): void
    {
        Schema::create('third_parties', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['producer', 'supplier', 'customer'])->index();
            $table->string('identification')->nullable(); // RUC / ID
            $table->string('zone')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('status')->default(true); // soft state, no physical deletion
            $table->timestamps();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('third_parties');
    }
};