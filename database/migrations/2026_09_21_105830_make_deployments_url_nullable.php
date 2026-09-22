<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Converted deployments have no known instance URL until they
     * enroll, so the column must accept null. Raw statement on MySQL
     * (no dbal installed); native change() on SQLite.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('deployments', function (Blueprint $table) {
                $table->string('url', 2048)->nullable()->change();
            });

            return;
        }

        DB::statement('ALTER TABLE deployments MODIFY url VARCHAR(2048) NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('deployments', function (Blueprint $table) {
                $table->string('url', 2048)->nullable(false)->change();
            });

            return;
        }

        DB::statement('ALTER TABLE deployments MODIFY url VARCHAR(2048) NOT NULL');
    }
};
