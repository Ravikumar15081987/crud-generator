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
        
        // 1. Ask the user for a role (Admin, User, etc.)
        $roleInput = $this->ask('Which role is this for? (e.g., Admin, Manager, or leave empty for default)');
        $role = $roleInput ? ucfirst(trim($roleInput)) : null;

        $all = $this->option('all') || (
            ! $this->option('controller') && ! $this->option('service') && 
            ! $this->option('repository') && ! $this->option('views') && 
            ! $this->option('requests') && ! $this->option('route')
        );

        // Repositories & Services usually remain global (not role-specific)
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

        // 2. Adjust Controller path based on the role
        if ($all || $this->option('controller')) {
            $controllerPath = $role ? "Http/Controllers/{$role}" : "Http/Controllers";
            $this->generateFile($name, $controllerPath, "{$name}Controller", 'controller.stub', $role);
        }

        // 3. Adjust View paths based on the role
        if ($all || $this->option('views')) {
            $this->generateView($name, 'create', $role);
            $this->generateView($name, '_form', $role); // Generates the shared form partial
            $this->generateView($name, 'index', $role);
            $this->generateView($name, 'show', $role);
            $this->generateView($name, 'edit', $role);
        }

        if ($all || $this->option('route')) {
            $this->appendRoute($name, $role);
        }

        $this->info("CRUD architecture for {$name} generated successfully.");
    }

    protected function generateFile($name, $path, $className, $stubName, $role = null)
    {
        $stubPath = __DIR__ . '/../Stubs/' . $stubName;
        $destinationPath = app_path("{$path}/{$className}.php");

        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($destinationPath));

        if (!\Illuminate\Support\Facades\File::exists($stubPath)) {
            $this->error("Stub not found: {$stubPath}");
            return;
        }
        
        $stubContent = \Illuminate\Support\Facades\File::get($stubPath);

        $modelName = $name; 
        $modelNameLowerCase = strtolower($name); 
        $modelNamePluralLowerCase = \Illuminate\Support\Str::plural($modelNameLowerCase); 

        // Dynamic Namespace and View Prefix based on Role
        $namespace = $role ? "App\Http\Controllers\\{$role}" : "App\Http\Controllers";
        $viewPrefix = $role ? strtolower($role) . '.' : '';

        $fileContent = str_replace(
            [
                '{{ class }}', 
                '{{ modelName }}', 
                '{{ model }}',                   
                '{{ modelNameLowerCase }}', 
                '{{ modelNamePluralLowerCase }}',
                '{{ namespace }}',
                '{{ viewPrefix }}'
            ], 
            [
                $className,
                $modelName, 
                $modelName,                  
                $modelNameLowerCase, 
                $modelNamePluralLowerCase,
                $namespace,
                $viewPrefix
            ], 
            $stubContent
        );

        \Illuminate\Support\Facades\File::put($destinationPath, $fileContent);
        $this->info("Created: {$destinationPath}");
    }

    protected function generateView($name, $viewType, $role = null)
    {
        $modelNameLowerCase = strtolower($name);
        $rolePath = $role ? strtolower($role) . '/' : '';
        $viewDir = resource_path("views/{$rolePath}{$modelNameLowerCase}");
        
        \Illuminate\Support\Facades\File::ensureDirectoryExists($viewDir);
        $destinationPath = $viewDir . "/{$viewType}.blade.php";
        
        // Use the specific stub for each view
        $stubPath = $this->getStubPath("view.{$viewType}.stub");

        if (\Illuminate\Support\Facades\File::exists($stubPath)) {
            $stubContent = \Illuminate\Support\Facades\File::get($stubPath);
            
            $routePrefix = $role ? strtolower($role) . '.' : '';
            $routeName = $routePrefix . $modelNameLowerCase;
            $viewPrefix = $role ? strtolower($role) . '.' : '';

            // Dynamically generate Bootstrap 5 form fields if generating _form.blade.php
            $formFieldsHtml = '';
            if ($viewType === '_form') {
                $modelClass = "\\App\\Models\\{$name}";
                if (class_exists($modelClass)) {
                    $model = new $modelClass();
                    $fillable = $model->getFillable();
                    
                    foreach ($fillable as $field) {
                        $label = ucwords(str_replace('_', ' ', $field));
                        // Generate Bootstrap 5 columns and inputs
                        $formFieldsHtml .= <<<HTML
                            <div class="col-md-6">
                                <label class="form-label">{$label} *</label>
                                <input type="text" name="{$field}" class="form-control" value="{{ old('{$field}', \${$modelNameLowerCase}->{$field} ?? '') }}" required placeholder="e.g. {$label}">
                            </div>\n
                        HTML;
                    }
                }
                if (empty($formFieldsHtml)) {
                    $formFieldsHtml = "                <!-- Define your \$fillable array in {$name}.php to auto-generate inputs -->\n";
                }
            }

            $fileContent = str_replace(
                ['{{ modelName }}', '{{ modelNameLowerCase }}', '{{ routeName }}', '{{ viewPrefix }}', '{{ formFields }}'], 
                [$name, $modelNameLowerCase, $routeName, $viewPrefix, $formFieldsHtml], 
                $stubContent
            );

            \Illuminate\Support\Facades\File::put($destinationPath, $fileContent);
            $this->info("Created: {$destinationPath}");
        }
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

    protected function appendRoute($name, $role = null)
    {
        $modelNameLowerCase = strtolower($name);
        
        if ($role) {
            $controllerNamespace = "\\App\\Http\\Controllers\\{$role}\\{$name}Controller";
            $prefix = strtolower($role);
            
            // Generates a route group with prefix, alias (as), and Spatie middleware
            $routeDefinition = "\nRoute::group(['prefix' => '{$prefix}', 'as' => '{$prefix}.', 'middleware' => ['role:{$role}']], function () {\n" .
                "    Route::resource('{$modelNameLowerCase}', {$controllerNamespace}::class);\n" .
                "});\n";
        } else {
            // Standard route definition if no role is provided
            $controllerNamespace = "\\App\\Http\\Controllers\\{$name}Controller";
            $routeDefinition = "\nRoute::resource('{$modelNameLowerCase}', {$controllerNamespace}::class);\n";
        }

        \Illuminate\Support\Facades\File::append(base_path('routes/web.php'), $routeDefinition);
        
        $this->info("Appended route for {$name} to routes/web.php");
    }

    protected function generateTrait()
    {
        $stubPath = __DIR__ . '/../Stubs/trait.safe_transaction.stub';
        $destinationPath = app_path('Traits/HandlesSafeTransactions.php');

        if (!File::exists(dirname($destinationPath))) {
            File::makeDirectory(dirname($destinationPath), 0755, true);
        }

        if (!File::exists($destinationPath)) {
            File::copy($stubPath, $destinationPath);
            $this->line("<info>Created Trait:</info> {$destinationPath}");
        }  
    }

    protected function getStubPath(string $stubName): string
    {
        // Check if the user has published custom stubs in their app
        $publishedPath = base_path("resources/vendor/crud-generator/stubs/{$stubName}");

        if (file_exists($publishedPath)) {
            return $publishedPath;
        }

        // Fall back to the default package stubs
        return __DIR__ . "/../Stubs/{$stubName}";
    }
}