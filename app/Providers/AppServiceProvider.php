<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
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
        // En desarrollo: modo estricto para detectar lazy loading, mass assignment silencioso
        // y acceso a propiedades no existentes antes de llegar a producción.
        Model::shouldBeStrict(! app()->isProduction());

        // En producción: forzar HTTPS para todos los URLs generados por la aplicación.
        if (app()->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
