<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('bill_of_lading_id')->nullable()->constrained('bills_of_lading');
            $table->string('invoice_number'); // Manual, lo escribe Manuel
            $table->date('issue_date');
            $table->string('currency', 3)->default('USD');
            $table->decimal('total_amount', 14, 2)->default(0); // suma de invoice_lines, se recalcula al guardar
            $table->text('comment')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->unique(['shipment_id', 'customer_id', 'invoice_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
