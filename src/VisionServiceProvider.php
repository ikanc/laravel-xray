<?php

namespace Ikabalzam\LaravelVision;

use Illuminate\Support\ServiceProvider;

class VisionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vision.php', 'vision');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/vision.php' => config_path('vision.php'),
            ], 'vision-config');

            $this->commands([
                VisionCommand::class,
            ]);
        }
    }
}
