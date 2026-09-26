<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scope features and pricing overrides to products. Existing feature
     * rows backfill to FlowEdu (or the first product) since the catalogue
     * was FlowEdu's; settings rows stay global (null product) as fallback.
     */
    public function up(): void
    {
        Schema::table('features', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        $fallbackId = DB::table('products')->where('slug', 'flowedu')->value('id')
            ?? DB::table('products')->orderBy('id')->value('id');

        if ($fallbackId !== null) {
            DB::table('features')->whereNull('product_id')->update(['product_id' => $fallbackId]);
        }

        Schema::table('features', function (Blueprint $table) {
            $table->dropUnique(['key']);
            $table->unique(['product_id', 'key']);
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->dropUnique(['key']);
            $table->unique(['key', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique(['key', 'product_id']);
            $table->dropColumn('product_id');
            $table->unique(['key']);
        });

        Schema::table('features', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'key']);
            $table->dropColumn('product_id');
            $table->unique(['key']);
        });
    }
};
