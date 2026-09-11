<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills_of_lading', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments');
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('bl_number'); // Manual, lo escribe Manuel
            $table->date('issue_date');
            $table->text('comment')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->unique(['shipment_id', 'customer_id', 'bl_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills_of_lading');
    }
};
