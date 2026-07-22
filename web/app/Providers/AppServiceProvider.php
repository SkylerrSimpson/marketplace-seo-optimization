<?php

namespace App\Providers;

use App\Scripts\ScriptRegistry;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton: config/scripts/*.php is parsed and validated once per
        // request (fail-fast at first resolution), not once per injection site.
        $this->app->singleton(ScriptRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // layouts.navigation renders on every authenticated page regardless of
        // which controller served it — a View Composer is the one place that needs
        // to know that, instead of every controller remembering to pass it.
        View::composer('layouts.navigation', function ($view): void {
            $view->with(
                'navMarketplaces',
                $this->app->make(ScriptRegistry::class)->all()->pluck('marketplace')->unique()->sort()->values(),
            );
        });
    }
}
