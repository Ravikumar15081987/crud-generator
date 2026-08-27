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

        // 2. Check for devrabiul/laravel-toaster-magic requirement
        if (!class_exists(\Devrabiul\ToastMagic\Facades\ToastMagic::class)) {
            $this->error('Error: devrabiul/laravel-toaster-magic package is required for UI notifications.');
            $this->warn('Please install it first using: composer require devrabiul/laravel-toaster-magic');
            return 1;
        }

        // 3. Generate base Facade and Service files if they don't exist
        $this->generateUiNotifyBaseFiles();

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
            $this->generateBaseRequestFiles();
            
            $roleFolder = $role ? ucfirst(strtolower($role)) : null;
            $requestPath = $roleFolder ? "Http/Requests/{$roleFolder}" : "Http/Requests";
            
            // Auto-generate smart validation rules based on model $fillable and $casts
            $rulesString = '';
            $modelClass = "\\App\\Models\\{$name}";
            if (class_exists($modelClass)) {
                $model = new $modelClass();
                $fillable = $model->getFillable();
                $casts = $model->getCasts(); // Reads the protected $casts array

                foreach ($fillable as $field) {
                    $rules = ['required'];
                    
                    // Determine data type based on casts or naming conventions
                    $castType = $casts[$field] ?? null;
                    
                    if ($castType === 'boolean' || $castType === 'bool' || \Illuminate\Support\Str::startsWith($field, ['is_', 'has_'])) {
                        $rules[] = 'boolean';
                    } elseif ($castType === 'integer' || $castType === 'int' || \Illuminate\Support\Str::endsWith($field, '_id')) {
                        $rules[] = 'integer';
                    } elseif (in_array($castType, ['date', 'datetime']) || \Illuminate\Support\Str::endsWith($field, '_at')) {
                        $rules[] = 'date';
                    } else {
                        $rules[] = 'string';
                        // Drop the max:255 limit for fields that are likely text areas
                        if (!in_array($field, ['description', 'body', 'content', 'notes'])) {
                            $rules[] = 'max:255';
                        }
                    }

                    $implodedRules = implode('|', $rules);
                    $rulesString .= "'{$field}' => '{$implodedRules}',\n            ";
                }
            } else {
                $rulesString = "// Define your validation rules here\n";
            }

            $this->generateFile($name, $requestPath, "Store{$name}Request", 'request.stub', $roleFolder, ['{{ rules }}' => $rulesString]);
            $this->generateFile($name, $requestPath, "Update{$name}Request", 'request.stub', $roleFolder, ['{{ rules }}' => $rulesString]);
        }

        if ($all || $this->option('controller')) {
            $controllerPath = $roleFolder ? "Http/Controllers/{$roleFolder}" : "Http/Controllers";
            $requestNamespace = $roleFolder ? "App\Http\Requests\\{$roleFolder}" : "App\Http\Requests";

            $this->generateFile($name, $controllerPath, "{$name}Controller", 'controller.stub', $roleFolder, [
                '{{ requestNamespace }}' => $requestNamespace
            ]);
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
    protected function generateFile($name, $path, $className, $stubName, $role = null, $extraReplacements = [])
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

        $roleFolder = $role ? ucfirst(strtolower($role)) : null;
        $namespace = $roleFolder ? "App\Http\Controllers\\{$roleFolder}" : "App\Http\Controllers";
        $viewPrefix = $roleFolder ? strtolower($roleFolder) . '.' : '';
        $requestNamespace = $roleFolder ? "App\Http\Requests\\{$roleFolder}" : "App\Http\Requests";        

        $search = [
            '{{ class }}', '{{ modelName }}', '{{ model }}',
            '{{ modelNameLowerCase }}', '{{ modelNamePluralLowerCase }}',
            '{{ namespace }}', '{{ viewPrefix }}', '{{ requestNamespace }}'
        ];

        $replace = [
            $className, $modelName, $modelName,
            $modelNameLowerCase, $modelNamePluralLowerCase,
            $namespace, $viewPrefix, $requestNamespace
        ];
        
        foreach ($extraReplacements as $key => $value) {
            $search[] = $key;
            $replace[] = $value;
        }

        $fileContent = str_replace($search, $replace, $stubContent);

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

    /**
     * Create the UiNotify Facade and Service if they don't already exist.
     */
    protected function generateUiNotifyBaseFiles()
    {
        $facadePath = app_path('Facades/UiNotify.php');
        $servicePath = app_path('Services/UiNotificationService.php');

        if (!File::exists($facadePath)) {
            File::ensureDirectoryExists(dirname($facadePath));
            File::copy(__DIR__ . '/../Stubs/ui.notify.facade.stub', $facadePath);
            $this->info("Generated base file: {$facadePath}");
        }

        if (!File::exists($servicePath)) {
            File::ensureDirectoryExists(dirname($servicePath));
            File::copy(__DIR__ . '/../Stubs/ui.notification.service.stub', $servicePath);
            $this->info("Generated base file: {$servicePath}");
        }
    }

    protected function generateBaseRequestFiles()
    {
        $baseRequestPath = app_path('Http/Requests/BaseRequest.php');

        if (!File::exists($baseRequestPath)) {
            File::ensureDirectoryExists(dirname($baseRequestPath));
            File::copy(__DIR__ . '/../Stubs/base.request.stub', $baseRequestPath);
            $this->info("Generated base file: {$baseRequestPath}");
        }
    }

    protected function generateErrorComponent()
    {
        $componentPath = resource_path('views/components/field-error.blade.php');

        if (!File::exists($componentPath)) {
            File::ensureDirectoryExists(dirname($componentPath));
            
            // Check this path exactly matches your stub file name!
            $stubPath = __DIR__ . '/../Stubs/component.field-error.stub';
            
            if (File::exists($stubPath)) {
                File::copy($stubPath, $componentPath);
                $this->info("Generated missing UI component: resources/views/components/field-error.blade.php");
            } else {
                $this->error("Could not find the component stub at: {$stubPath}");
            }
        }
    }
}