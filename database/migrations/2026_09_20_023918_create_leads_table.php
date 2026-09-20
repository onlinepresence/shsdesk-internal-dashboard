<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('contact_name');
            $table->string('contact_email');
            $table->string('contact_phone', 50)->nullable();
            $table->string('school')->nullable();
            $table->string('band', 64);
            $table->json('modules');
            $table->decimal('quote_upfront', 10, 2);
            $table->decimal('quote_renewal', 10, 2);
            $table->json('quote_lines');
            $table->string('status', 32)->default('new');
            $table->timestamps();

            $table->index(['contact_email', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
