<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AccessControlSeeder extends Seeder
{
    /**
     * The abilities gating the ops surface, and the role holding them.
     *
     * @var list<string>
     */
    public const PERMISSIONS = ['manage-users', 'manage-catalogue', 'ops.access'];

    public const SUPER_ADMIN_ROLE = 'super-admin';

    /**
     * Seed permissions and the super-admin role. Additive only —
     * existing rows are never modified, so re-running is always safe.
     */
    public function run(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate(['name' => self::SUPER_ADMIN_ROLE, 'guard_name' => 'web']);

        $role->givePermissionTo(self::PERMISSIONS);
    }
}
