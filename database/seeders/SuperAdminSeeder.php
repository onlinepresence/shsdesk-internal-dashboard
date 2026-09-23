<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public const EMAIL = 'admin@controldesk.com';

    public const PASSWORD = 'Admin@123';

    /**
     * Create the first owner account. Creates the row ONLY when the
     * email is absent — an existing row (even with a different
     * password) is never touched, so re-running cannot clobber it.
     */
    public function run(): void
    {
        $this->call(AccessControlSeeder::class);

        if (User::where('email', self::EMAIL)->exists()) {
            $this->command->info('Owner account already present; nothing changed.');

            return;
        }

        $user = User::query()->create([
            'name' => 'ControlDesk Admin',
            'email' => self::EMAIL,
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
            'must_change_password' => true,
        ]);

        $user->assignRole(AccessControlSeeder::SUPER_ADMIN_ROLE);

        $this->command->info('Owner account created ('.self::EMAIL.').');
    }
}
