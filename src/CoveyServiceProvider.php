<?php

namespace Covey\Laravel;

use Covey\Laravel\Console\TokenCommand;
use Covey\Laravel\Http\Middleware\AuthenticateCoveyToken;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CoveyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/covey.php', 'covey');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/covey.php' => config_path('covey.php')], 'covey-config');

        $router = $this->app['router'];
        $router->aliasMiddleware('covey.token', AuthenticateCoveyToken::class);

        Route::group([
            'prefix' => config('covey.prefix', 'covey/v1'),
            // No web middleware: there is no session, no CSRF, no cookie here.
            // The token is the whole authentication.
            'middleware' => ['api'],
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/covey.php');
        });

        if ($this->app->runningInConsole()) {
            $this->commands([TokenCommand::class]);
        }
    }
}
