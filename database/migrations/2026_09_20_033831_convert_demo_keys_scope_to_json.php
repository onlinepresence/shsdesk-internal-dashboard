<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wrap legacy scalar scopes so every row holds a JSON array, then
     * widen the column where the driver enforces lengths. SQLite
     * ignores declared lengths, so only MySQL needs the rewrite.
     */
    public function up(): void
    {
        foreach (DB::table('demo_keys')->pluck('scope', 'id') as $id => $scope) {
            if (! is_array(json_decode((string) $scope, true))) {
                DB::table('demo_keys')->where('id', $id)->update(['scope' => json_encode([$scope])]);
            }
        }

        if (DB::getDriverName() === 'mysql' && Schema::hasColumn('demo_keys', 'scope')) {
            DB::statement('ALTER TABLE demo_keys MODIFY scope JSON NOT NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (DB::table('demo_keys')->pluck('scope', 'id') as $id => $scope) {
            $decoded = json_decode((string) $scope, true);

            if (is_array($decoded)) {
                DB::table('demo_keys')->where('id', $id)->update(['scope' => (string) ($decoded[0] ?? 'full')]);
            }
        }

        if (DB::getDriverName() === 'mysql' && Schema::hasColumn('demo_keys', 'scope')) {
            DB::statement('ALTER TABLE demo_keys MODIFY scope VARCHAR(64) NOT NULL');
        }
    }
};
