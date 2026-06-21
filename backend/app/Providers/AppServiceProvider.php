<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // super-admin and admin short-circuit every authorization check, so they
        // implicitly hold any current or future permission without being reseeded.
        Gate::before(function ($user, $ability) {
            return $user->hasAnyRole(['super-admin', 'admin']) ? true : null;
        });
    }
}
