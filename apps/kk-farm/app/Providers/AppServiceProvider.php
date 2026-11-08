<?php

namespace App\Providers;

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
        // D-088: values cached for a request (Tenant::memo) are dropped as soon as anything is written, by a model or a
        // plain query, so a cached value is never older than the data.
        \Illuminate\Support\Facades\DB::listen(function (\Illuminate\Database\Events\QueryExecuted $q) {
            if (preg_match('/^\s*(insert|update|delete|truncate)\b/i', $q->sql)) {
                \App\Support\Tenant::flush();
            }
        });
    }
}
