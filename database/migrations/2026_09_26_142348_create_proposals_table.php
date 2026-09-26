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
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_template_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('template_version');
            $table->string('proposal_no')->unique();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('deployment_id')->nullable()->constrained()->nullOnDelete();
            $table->json('values');
            $table->json('sections_snapshot');
            $table->json('pricing_snapshot');
            $table->string('status', 20)->default('draft');
            $table->timestamps();

            $table->index(['proposal_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};
