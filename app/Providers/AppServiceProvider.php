<?php

namespace App\Providers;

use App\Models\User;
use App\Support\EnvWriter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EnvWriter::class, fn (): EnvWriter => EnvWriter::forApplication());
    }

    /**
     * Abilities map 1:1 onto seeded Spatie permissions; the super-admin
     * role holds them all and Gate::before short-circuits it past every
     * check, present and future.
     */
    public function boot(): void
    {
        Gate::before(fn (User $user): ?bool => $user->hasRole('super-admin') ? true : null);
        Gate::define('manage-users', fn (User $user): bool => $user->hasPermissionTo('manage-users'));
        Gate::define('manage-catalogue', fn (User $user): bool => $user->hasPermissionTo('manage-catalogue'));
        Gate::define('ops.access', fn (User $user): bool => $user->hasPermissionTo('ops.access'));
    }
}
