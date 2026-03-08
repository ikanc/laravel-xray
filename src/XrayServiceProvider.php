<?php

namespace Ikabalzam\LaravelXray;

use Illuminate\Support\ServiceProvider;

class XrayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/xray.php', 'xray');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/xray.php' => config_path('xray.php'),
            ], 'xray-config');

            $this->commands([
                XrayCommand::class,
            ]);
        }
    }
}
