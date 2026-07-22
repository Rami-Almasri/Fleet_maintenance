<?php

namespace App\Providers;

use App\Contracts\VehicleExpenseProvider;
use App\Services\Expenses\ExcelVehicleExpenseProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The single seam for vehicle expense. Excel today, Odoo later — swap the class here (driven by
        // config('expenses.provider')); every consumer keeps depending only on the interface.
        $this->app->bind(VehicleExpenseProvider::class, function ($app) {
            return match (config('expenses.provider', 'excel')) {
                // 'odoo' => $app->make(\App\Services\Expenses\OdooVehicleExpenseProvider::class),
                default => $app->make(ExcelVehicleExpenseProvider::class),
            };
        });
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
