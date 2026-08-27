<?php
namespace NirikshaOccesstech\CrudGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeCrudCommand extends Command
{
    protected $signature = 'make:crud {name : The entity name (e.g., User)}
                            {--c|controller : Create the controller}
                            {--s|service : Create the service layer}
                            {--r|repository : Create the repository layer}
                            {--w|views : Create the Blade views}
                            {--Q|requests : Create the Form Requests}
                            {--R|route : Append resource route to web.php}
                            {--a|all : Create all files and append route}';

    protected $description = 'Create CRUD operations with Repository and Service layers';

    public function handle()
    {
        $name = $this->argument('name');
        $all = $this->option('all') || (
            ! $this->option('controller') && ! $this->option('service') && 
            ! $this->option('repository') && ! $this->option('views') && 
            ! $this->option('requests') && ! $this->option('route')
        );

        if ($all || $this->option('repository')) {
            $this->generateFile($name, 'Repositories/Contracts', "{$name}RepositoryInterface", 'repository.contract.stub');
            $this->generateFile($name, 'Repositories/Eloquent', "{$name}Repository", 'repository.impl.stub');
        }

        if ($all || $this->option('service')) {
            $this->generateFile($name, 'Services/Contracts', "{$name}ServiceInterface", 'service.contract.stub');
            $this->generateFile($name, 'Services/Implementation', "{$name}Service", 'service.impl.stub');
        }

        if ($all || $this->option('requests')) {
            $this->generateFile($name, 'Http/Requests', "Store{$name}Request", 'request.stub');
            $this->generateFile($name, 'Http/Requests', "Update{$name}Request", 'request.stub');
        }

        if ($all || $this->option('controller')) {
            $this->generateFile($name, 'Http/Controllers', "{$name}Controller", 'controller.stub');
        }

        if ($all || $this->option('views')) {
            $this->generateView($name, 'create');
            $this->generateView($name, 'edit');
        }

        if ($all || $this->option('route')) {
            $this->appendRoute($name);
        }

        $this->info("CRUD architecture for {$name} generated successfully.");
    }

    protected function generateFile($name, $path, $className, $stubName)
    {
        $stubPath = __DIR__ . '/../Stubs/' . $stubName;
        $destinationPath = app_path("{$path}/{$className}.php");
        $this->buildAndSaveClass($name, $className, $stubPath, $destinationPath);
    }

    protected function generateView($name, $viewName)
    {
        $stubPath = __DIR__ . '/../Stubs/view.form.stub';
        $folderName = strtolower($name);
        $destinationPath = resource_path("views/{$folderName}/{$viewName}.blade.php");
        $this->buildAndSaveClass($name, $viewName, $stubPath, $destinationPath);
    }

    protected function buildAndSaveClass($name, $className, $stubPath, $destinationPath)
    {
        if (!File::exists(dirname($destinationPath))) {
            File::makeDirectory(dirname($destinationPath), 0755, true);
        }

        if (File::exists($destinationPath)) {
            $this->warn("File {$destinationPath} already exists. Skipping.");
            return;
        }

        $stub = File::get($stubPath);
        $content = str_replace(
            ['{{ class }}', '{{ model }}', '{{ modelVariable }}'],
            [$className, $name, strtolower($name)],
            $stub
        );

        File::put($destinationPath, $content);
        $this->line("<info>Created:</info> {$destinationPath}");
    }

    protected function appendRoute($name)
    {
        $routeFile = base_path('routes/web.php');
        $routeUri = Str::plural(Str::kebab($name)); 
        $controllerNamespace = "\\App\\Http\\Controllers\\{$name}Controller::class";
        $routeDefinition = "\nRoute::resource('{$routeUri}', {$controllerNamespace});\n";

        if (!File::exists($routeFile)) return;

        $content = File::get($routeFile);
        if (str_contains($content, "Route::resource('{$routeUri}'")) {
            $this->warn("Route for {$name} already exists. Skipping.");
            return;
        }

        File::append($routeFile, $routeDefinition);
        $this->line("<info>Appended route:</info> {$routeFile}");
    }
}