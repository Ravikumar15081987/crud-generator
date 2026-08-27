<?php
namespace NirikshaOccesstech\CrudGenerator;

use Illuminate\Support\ServiceProvider;
use NirikshaOccesstech\CrudGenerator\Commands\MakeCrudCommand;
use NirikshaOccesstech\CrudGenerator\Commands\DeleteCrudCommand;

class CrudGeneratorServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeCrudCommand::class,
                DeleteCrudCommand::class
            ]);
        }

        if ($this->app->runningInConsole()) {
            // Publish stubs to the application's resource directory
            $this->publishes([
                __DIR__ . '/../Stubs' => base_path('resources/vendor/crud-generator/stubs'),
            ], 'crud-generator-stubs');

            // Allow publishing the component stub manually if needed
            $this->publishes([
                __DIR__ . '/Stubs/validation.error.stub' => resource_path('views/components/field-error.blade.php'),
            ], 'crud-generator-components');
            
        }

    }
    
    public function register()
    {
        // Bind the generated UiNotificationService to the 'ui.notify' alias
        $this->app->singleton('ui.notify', function ($app) {
            $serviceClass = '\\App\\Services\\UiNotificationService';
            if (class_exists($serviceClass)) {
                return new $serviceClass();
            }
        });
    }
}