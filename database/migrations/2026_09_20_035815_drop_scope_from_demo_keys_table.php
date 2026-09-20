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
            $table->dropColumn('scope');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('demo_keys', function (Blueprint $table) {
            $table->string('scope', 64)->nullable()->after('code_hash');
        });
    }
};
