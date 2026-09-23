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
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage-catalogue', fn (User $user): bool => $user->hasRole('super-admin'));
        Gate::define('ops.access', fn (User $user): bool => $user->hasRole('super-admin'));
    }
}
