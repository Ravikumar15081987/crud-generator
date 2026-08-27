<?php
namespace NirikshaOccesstech\CrudGenerator;

use Illuminate\Support\ServiceProvider;
use NirikshaOccesstech\CrudGenerator\Commands\MakeCrudCommand;

class CrudGeneratorServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeCrudCommand::class,
            ]);
        }

        if ($this->app->runningInConsole()) {
            // Publish stubs to the application's resource directory
            $this->publishes([
                __DIR__ . '/../Stubs' => base_path('resources/vendor/crud-generator/stubs'),
            ], 'crud-generator-stubs');
        }
    }
}