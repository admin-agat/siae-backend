<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_number');
            $table->foreignId('shipping_line_id')->nullable()->constrained('shipping_lines');
            $table->unsignedSmallInteger('week');
            $table->unsignedSmallInteger('year');
            $table->date('entry_date');
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->index(['week', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
