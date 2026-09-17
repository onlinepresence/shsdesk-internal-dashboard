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
        Schema::table('licences', function (Blueprint $table) {
            $table->json('price_snapshot')->nullable()->after('caps');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('licences', function (Blueprint $table) {
            $table->dropColumn('price_snapshot');
        });
    }
};
