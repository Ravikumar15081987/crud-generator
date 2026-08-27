<?php

namespace NirikshaOccesstech\CrudGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeCrudCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:crud {name : The name of the model}
                            {--all : Generate all layers}
                            {--controller : Generate Controller only}
                            {--service : Generate Service layer only}
                            {--repository : Generate Repository layer only}
                            {--views : Generate Blade views only}
                            {--requests : Generate Form Requests only}
                            {--route : Append routes only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate full CRUD architecture (Repository, Service, Controller, Requests, Views, Routes)';

    public function handle()
    {
        // 1. Check for spatie/laravel-permission package requirement
        if (!class_exists(\Spatie\Permission\Models\Role::class)) {
            $this->error('Error: spatie/laravel-permission package is required to run this CRUD generator.');
            $this->warn('Please install it first using: composer require spatie/laravel-permission');
            return 1;
        }

        $name = ucfirst($this->argument('name'));

        // 2. Fetch roles from DB and ask user to select or enter custom role
        $roles = \Spatie\Permission\Models\Role::pluck('name')->toArray();
        array_unshift($roles, 'None (Default)');
        $roles[] = 'Type Manually...';

        $selectedRole = $this->choice('Which role is this CRUD for?', $roles, 0);

        if ($selectedRole === 'None (Default)') {
            $role = null;
        } elseif ($selectedRole === 'Type Manually...') {
            $customRole = $this->ask('Enter the role name (e.g., SubAdmin, Manager)');
            $role = $customRole ? ucfirst(trim($customRole)) : null;
        } else {
            $role = $selectedRole;
        }

        // 3. Ask for the layout name to extend in Blade views
        $layoutName = $this->ask('Enter the layout name to extend (e.g., layouts/layoutMaster or layouts.app)', 'layouts.app');

        // 4. Determine options passed
        $all = $this->option('all') || (
            !$this->option('controller') && !$this->option('service') &&
            !$this->option('repository') && !$this->option('views') &&
            !$this->option('requests') && !$this->option('route')
        );

        // 5. Generate Layers
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
            $controllerPath = $role ? "Http/Controllers/{$role}" : "Http/Controllers";
            $this->generateFile($name, $controllerPath, "{$name}Controller", 'controller.stub', $role);
        }

        if ($all || $this->option('views')) {
            $this->generateView($name, 'create', $role, $layoutName);
            $this->generateView($name, '_form', $role, $layoutName);
            $this->generateView($name, 'index', $role, $layoutName);
            $this->generateView($name, 'show', $role, $layoutName);
            $this->generateView($name, 'edit', $role, $layoutName);
        }

        if ($all || $this->option('route')) {
            $this->appendRoute($name, $role);
        }

        $this->info("CRUD architecture for {$name} generated successfully.");
        return 0;
    }

    /**
     * Generate class files from stubs with dynamic replacements.
     */
    protected function generateFile($name, $path, $className, $stubName, $role = null)
    {
        $stubPath = __DIR__ . '/../Stubs/' . $stubName;
        $destinationPath = app_path("{$path}/{$className}.php");

        File::ensureDirectoryExists(dirname($destinationPath));

        if (!File::exists($stubPath)) {
            $this->error("Stub not found: {$stubPath}");
            return;
        }

        $stubContent = File::get($stubPath);

        $modelName = $name;
        $modelNameLowerCase = strtolower($name);
        $modelNamePluralLowerCase = Str::plural($modelNameLowerCase);

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

        File::put($destinationPath, $fileContent);
        $this->info("Created: {$destinationPath}");
    }

    /**
     * Generate Blade views and dynamically build form fields from Model fillable array.
     */
    protected function generateView($name, $viewType, $role = null, $layoutName = 'layouts.app')
    {
        $modelNameLowerCase = strtolower($name);
        $rolePath = $role ? strtolower($role) . '/' : '';
        $viewDir = resource_path("views/{$rolePath}{$modelNameLowerCase}");

        File::ensureDirectoryExists($viewDir);
        $destinationPath = $viewDir . "/{$viewType}.blade.php";

        $stubPath = __DIR__ . "/../Stubs/view.{$viewType}.stub";

        if (File::exists($stubPath)) {
            $stubContent = File::get($stubPath);

            $routePrefix = $role ? strtolower($role) . '.' : '';
            $routeName = $routePrefix . $modelNameLowerCase;
            $viewPrefix = $role ? strtolower($role) . '.' : '';

            $formFieldsHtml = '';
            if ($viewType === '_form') {
                $modelClass = "\\App\\Models\\{$name}";
                if (class_exists($modelClass)) {
                    $model = new $modelClass();
                    $fillable = $model->getFillable();

                    foreach ($fillable as $field) {
                        $label = ucwords(str_replace('_', ' ', $field));
                        $formFieldsHtml .= <<<HTML
                <div class="col-md-6">
                    <label class="form-label">{$label} *</label>
                    <input type="text" name="{$field}" class="form-control @error('{$field}') is-invalid @enderror" value="{{ old('{$field}', \${$modelNameLowerCase}->{$field} ?? '') }}" placeholder="e.g. {$label}">
                    <x-field-error field="{$field}" />
                </div>\n
HTML;
                    }
                }
                if (empty($formFieldsHtml)) {
                    $formFieldsHtml = "                <!-- Define your \$fillable array in {$name}.php to auto-generate inputs -->\n";
                }
            }

            $fileContent = str_replace(
                [
                    '{{ modelName }}',
                    '{{ modelNameLowerCase }}',
                    '{{ routeName }}',
                    '{{ viewPrefix }}',
                    '{{ formFields }}',
                    '{{ layoutName }}'
                ],
                [
                    $name,
                    $modelNameLowerCase,
                    $routeName,
                    $viewPrefix,
                    $formFieldsHtml,
                    $layoutName
                ],
                $stubContent
            );

            File::put($destinationPath, $fileContent);
            $this->info("Created: {$destinationPath}");
        } else {
            $this->error("Stub not found: {$stubPath}");
        }
    }

    /**
     * Append generated route resource to routes/web.php.
     */
    protected function appendRoute($name, $role = null)
    {
        $modelNameLowerCase = strtolower($name);

        if ($role) {
            $controllerNamespace = "\\App\\Http\\Controllers\\{$role}\\{$name}Controller";
            $prefix = strtolower($role);

            $routeDefinition = "\nRoute::group(['prefix' => '{$prefix}', 'as' => '{$prefix}.', 'middleware' => ['role:{$role}']], function () {\n" .
                "    Route::resource('{$modelNameLowerCase}', {$controllerNamespace}::class);\n" .
                "});\n";
        } else {
            $controllerNamespace = "\\App\\Http\\Controllers\\{$name}Controller";
            $routeDefinition = "\nRoute::resource('{$modelNameLowerCase}', {$controllerNamespace}::class);\n";
        }

        File::append(base_path('routes/web.php'), $routeDefinition);
        $this->info("Appended route for {$name} to routes/web.php");
    }
}