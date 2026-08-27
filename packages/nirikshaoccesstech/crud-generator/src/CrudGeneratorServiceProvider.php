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
    }
}