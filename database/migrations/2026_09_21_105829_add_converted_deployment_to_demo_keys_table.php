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
        Schema::table('demo_keys', function (Blueprint $table) {
            $table->foreignId('converted_deployment_id')->nullable()->constrained('deployments')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('demo_keys', function (Blueprint $table) {
            $table->dropConstrainedForeignId('converted_deployment_id');
        });
    }
};
