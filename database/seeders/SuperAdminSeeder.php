<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class SuperAdminSeeder extends Seeder
{
    /**
     * Grant the super-admin role. Explicitly env-gated and never called
     * from DatabaseSeeder — run by hand for a chosen local user:
     *
     *   SUPERADMIN_EMAIL=you@example.com php artisan db:seed --class=SuperAdminSeeder
     */
    public function run(): void
    {
        $email = trim((string) env('SUPERADMIN_EMAIL', 'matrix@me.com'));

        if ($email === '') {
            $this->command->warn('Set SUPERADMIN_EMAIL to grant the super-admin role.');

            return;
        }

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->command->warn("No user found with email {$email}.");

            return;
        }

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $user->assignRole('super-admin');

        $this->command->info("Granted super-admin to {$email}.");
    }
}
