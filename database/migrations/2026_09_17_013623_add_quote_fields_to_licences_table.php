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
            $table->string('hosting_mode')->nullable()->after('caps');
            $table->decimal('implementation_fee', 10, 2)->nullable()->after('hosting_mode');
            $table->decimal('config_fee', 10, 2)->nullable()->after('implementation_fee');
            $table->decimal('migration_fee', 10, 2)->nullable()->after('config_fee');
            $table->unsignedInteger('training_admin')->default(0)->after('migration_fee');
            $table->unsignedInteger('training_teacher')->default(0)->after('training_admin');
            $table->unsignedInteger('training_onsite')->default(0)->after('training_teacher');
            $table->boolean('founding_client')->default(false)->after('training_onsite');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('licences', function (Blueprint $table) {
            $table->dropColumn([
                'hosting_mode',
                'implementation_fee',
                'config_fee',
                'migration_fee',
                'training_admin',
                'training_teacher',
                'training_onsite',
                'founding_client',
            ]);
        });
    }
};
