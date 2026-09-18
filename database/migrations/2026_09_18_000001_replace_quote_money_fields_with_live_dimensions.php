<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace the stale money-fee quote columns with the live FlowEdu
     * quote dimensions: config_setup / migration booleans priced from
     * settings storage. The implementation_fee concept does not exist in
     * the live FlowEdu maths.
     */
    public function up(): void
    {
        Schema::table('licences', function (Blueprint $table) {
            $table->dropColumn(['implementation_fee', 'config_fee', 'migration_fee']);
            $table->boolean('config_setup')->default(false)->after('hosting_mode');
            $table->boolean('migration')->default(false)->after('config_setup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('licences', function (Blueprint $table) {
            $table->dropColumn(['config_setup', 'migration']);
            $table->decimal('implementation_fee', 10, 2)->nullable()->after('hosting_mode');
            $table->decimal('config_fee', 10, 2)->nullable()->after('implementation_fee');
            $table->decimal('migration_fee', 10, 2)->nullable()->after('config_fee');
        });
    }
};
