<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * customers
     * Extends third_parties when type = 'customer'.
     * Holds fields specific to a buyer (e.g. JSC GRAND-TRADE, RVI RUSSIAN VENTURE INVESTMENTS JSC).
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('third_party_id')
                ->constrained('third_parties')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('customer_code')->nullable(); // e.g. "00002"
            $table->string('country')->nullable();
            $table->string('contact_name')->nullable();
            $table->enum('negotiation_type', ['contract', 'spot'])->index();
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->unique('third_party_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};