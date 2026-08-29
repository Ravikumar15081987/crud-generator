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
                            {--route : Append routes only}
                            {--api : Generate Api Resources} 
                            {--media : Attach Media}';

   
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate full CRUD architecture (Controller, Service, Repository, Requests, Views, Routes) for a given model. Use --api for API endpoints only. Use --media for Spatie MediaLibrary support.';

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

        $generateAll = $this->option('all');

        // 3. Generate base Facade and Service files if they don't exist
        $this->generateUiNotifyBaseFiles();
        $this->generateBaseDataTableFiles();

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
            $hasSoftDeletes = false;
            $hasSpatieMedia = $this->option('media');

            if (class_exists($modelClass)) {
                $model = new $modelClass();               

                $traits = class_uses_recursive($modelClass);
                $hasSoftDeletes = in_array('Illuminate\Database\Eloquent\SoftDeletes', $traits);
                if (in_array('Spatie\MediaLibrary\InteractsWithMedia', $traits)) {
                    $hasSpatieMedia = true;
                }

                $fillable = $model->getFillable();
                $casts = $model->getCasts(); // Reads the protected $casts array

                // Logic to insert inside your view generation for _form.blade.php
                $formFieldsHTML = '';
                
                foreach ($fillable as $field) {
                    // 1. Smart Image/File Uploads
                    if (in_array($field, ['image', 'photo', 'avatar', 'document', 'file'])) {
                        $formFieldsHTML .= <<<HTML
                                    <div class="mb-3">
                                        <label for="{$field}">{{ ucfirst('$field') }}</label>
                                        <input type="file" name="{$field}" class="form-control" id="{$field}">
                                        <x-field-error field="{$field}" />
                                    </div>\n
                        HTML;
                    } 
                    // 2. Smart Relationship Dropdowns
                    elseif (\Illuminate\Support\Str::endsWith($field, '_id')) {
                        $relationName = str_replace('_id', '', $field);
                        $relationVar = \Illuminate\Support\Str::plural($relationName); // e.g., 'categories'
                        
                        $formFieldsHTML .= <<<HTML
                                    <div class="mb-3">
                                        <label for="{$field}">{{ ucfirst('$relationName') }}</label>
                                        <select name="{$field}" class="form-select" id="{$field}">
                                            <option value="">Select {{ ucfirst('$relationName') }}</option>
                                            @foreach(\App\Models\\ucfirst($relationName)::all() as \$item)
                                                <option value="{{ \$item->id }}" {{ old('{$field}', \$model->{$field} ?? '') == \$item->id ? 'selected' : '' }}>
                                                    {{ \$item->name ?? \$item->title ?? \$item->id }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <x-field-error field="{$field}" />
                                    </div>\n
                        HTML;
                    } 
                    // 3. Standard Text Inputs
                    else {
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
                } 
            }
            else {
                $rulesString = "// Define your validation rules here\n";
            }

            $this->generateFile($name, $requestPath, "Store{$name}Request", 'request.stub', $roleFolder, ['{{ rules }}' => $rulesString]);
            $this->generateFile($name, $requestPath, "Update{$name}Request", 'request.stub', $roleFolder, ['{{ rules }}' => $rulesString]);
        }

        if ($all || $this->option('controller')) {
            $controllerPath = $roleFolder ? "Http/Controllers/{$roleFolder}" : "Http/Controllers";
            $requestNamespace = $roleFolder ? "App\Http\Requests\\{$roleFolder}" : "App\Http\Requests";
            
            // Switch stub based on API option
            $stubName = $this->option('api') ? 'controller.api.stub' : 'controller.stub';

            $this->generateFile($name, $controllerPath, "{$name}Controller", $stubName, $roleFolder, [
                '{{ requestNamespace }}' => $requestNamespace
            ]);
        }

        // Only generate views if NOT in API mode
        if (!$this->option('api') && ($all || $this->option('views'))) {
            $this->generateErrorComponent();

            $this->generateView($name, 'create', $role, $layoutName);
            $this->generateView($name, '_form', $role, $layoutName);
            $this->generateView($name, 'index', $role, $layoutName);
            $this->generateView($name, 'show', $role, $layoutName);
            $this->generateView($name, 'edit', $role, $layoutName);

            $this->generateActionComponent($name, $role);
            $this->generateDataTableConfigIfNeeded($name, $role);
        }

        if ($all || $this->option('route')) {
            $this->appendRoute($name, $role, $this->option('api'));
        }

        $this->info("CRUD architecture for {$name} generated successfully.");
        return 0;
    }

    /**
     * Generate class files from stubs with dynamic replacements.
     */
    // protected function generateFile($name, $path, $className, $stubName, $role = null, $extraReplacements = [])
    // {
    //     $stubPath = __DIR__ . '/../Stubs/' . $stubName;
    //     $destinationPath = app_path("{$path}/{$className}.php");

    //     File::ensureDirectoryExists(dirname($destinationPath));

    //     if (!File::exists($stubPath)) {
    //         $this->error("Stub not found: {$stubPath}");
    //         return;
    //     }

    //     $stubContent = File::get($stubPath);

    //     $modelName = $name;
    //     $modelNameLowerCase = strtolower($name);
    //     $modelNamePluralLowerCase = Str::plural($modelNameLowerCase);

    //     $roleFolder = $role ? ucfirst(strtolower($role)) : null;
    //     $namespace = $roleFolder ? "App\Http\Controllers\\{$roleFolder}" : "App\Http\Controllers";
    //     $viewPrefix = $roleFolder ? strtolower($roleFolder) . '.' : '';
    //     $requestNamespace = $roleFolder ? "App\Http\Requests\\{$roleFolder}" : "App\Http\Requests";        

    //     $search = [
    //         '{{ class }}', '{{ modelName }}', '{{ model }}',
    //         '{{ modelNameLowerCase }}', '{{ modelNamePluralLowerCase }}',
    //         '{{ namespace }}', '{{ viewPrefix }}', '{{ requestNamespace }}'
    //     ];

    //     $replace = [
    //         $className, $modelName, $modelName,
    //         $modelNameLowerCase, $modelNamePluralLowerCase,
    //         $namespace, $viewPrefix, $requestNamespace
    //     ];
        
    //     foreach ($extraReplacements as $key => $value) {
    //         $search[] = $key;
    //         $replace[] = $value;
    //     }

    //     $fileContent = str_replace($search, $replace, $stubContent);

    //     File::put($destinationPath, $fileContent);
    //     $this->info("Created: {$destinationPath}");
    // }

    protected function generateFile($name, $path, $className, $stubName, $role = null, $extraReplacements = [])
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
        
        // Add this line to define the plural kebab format
        $modelPluralKebab = \Illuminate\Support\Str::kebab(\Illuminate\Support\Str::plural($name));

        $roleFolder = $role ? ucfirst(strtolower($role)) : null;
        $namespace = $roleFolder ? "App\Http\Controllers\\{$roleFolder}" : "App\Http\Controllers";
        $viewPrefix = $roleFolder ? strtolower($roleFolder) . '.' : '';
        $requestNamespace = $roleFolder ? "App\Http\Requests\\{$roleFolder}" : "App\Http\Requests";        

        // Add '{{ model-plural-kebab }}' to both arrays
        $search = [
            '{{ class }}', '{{ modelName }}', '{{ model }}',
            '{{ modelNameLowerCase }}', '{{ modelNamePluralLowerCase }}',
            '{{ namespace }}', '{{ viewPrefix }}', '{{ requestNamespace }}',
            '{{ model-plural-kebab }}'
        ];

        $replace = [
            $className, $modelName, $modelName,
            $modelNameLowerCase, $modelNamePluralLowerCase,
            $namespace, $viewPrefix, $requestNamespace,
            $modelPluralKebab
        ];
        
        foreach ($extraReplacements as $key => $value) {
            $search[] = $key;
            $replace[] = $value;
        }

        $fileContent = str_replace($search, $replace, $stubContent);

        \Illuminate\Support\Facades\File::put($destinationPath, $fileContent);
        $this->info("Created: {$destinationPath}");
    }

    /**
     * Generate Blade views and dynamically build form fields from Model fillable array.
     */
    protected function generateView($name, $viewType, $role = null, $layoutName = 'layouts.app')
    {
        $modelNameLowerCase = strtolower($name);
        $rolePath = $role ? strtolower($role) . '/' : '';
        $modelPluralKebab = \Illuminate\Support\Str::kebab(\Illuminate\Support\Str::plural($name));
        
        // Ensure all views land inside the pluralized resource path (e.g., resources/views/admin/posts)
        $viewDir = resource_path("views/{$rolePath}{$modelPluralKebab}");

        \Illuminate\Support\Facades\File::ensureDirectoryExists($viewDir);
        $destinationPath = $viewDir . "/{$viewType}.blade.php";

        $stubPath = __DIR__ . "/../Stubs/view.{$viewType}.stub";

        if (\Illuminate\Support\Facades\File::exists($stubPath)) {
            $stubContent = \Illuminate\Support\Facades\File::get($stubPath);

            $routePrefix = $role ? strtolower($role) . '.' : '';
            $routeName = $routePrefix . $modelPluralKebab;
            $viewPrefix = $role ? strtolower($role) . '.' : '';

            $formFieldsHtml = '';
            
            if ($viewType === '_form') {
                $modelClass = "\\App\\Models\\{$name}";
                if (class_exists($modelClass)) {
                    $model = new $modelClass();
                    $fillable = $model->getFillable();
                    $casts = $model->getCasts(); // Retrieve the casts array

                    foreach ($fillable as $field) {
                        $label = ucwords(str_replace('_', ' ', $field));
                        
                        // Detect boolean by cast array OR common naming conventions
                        $castType = $casts[$field] ?? null;
                        $isBoolean = ($castType === 'boolean' || $castType === 'bool' || \Illuminate\Support\Str::startsWith($field, ['is_', 'has_']));
                        
                        // 1. Smart Image/File Uploads
                        if (in_array($field, ['image', 'photo', 'avatar', 'document', 'file', 'logo'])) {
                            $formFieldsHtml .= <<<HTML
                            <div class="col-md-6 mb-3">
                                <label for="{$field}" class="form-label">{$label}</label>
                                <input type="file" name="{$field}" class="form-control @error('{$field}') is-invalid @enderror" id="{$field}">
                                <x-field-error field="{$field}" />
                            </div>\n
HTML;
                        } 
                        // 2. Smart Relationship Dropdowns
                        elseif (\Illuminate\Support\Str::endsWith($field, '_id')) {
                            $relationName = str_replace('_id', '', $field);
                            $relationModel = ucfirst(\Illuminate\Support\Str::camel($relationName));
                            
                            $formFieldsHtml .= <<<HTML
                                <div class="col-md-6 mb-3">
                                    <label for="{$field}" class="form-label">{$label}</label>
                                    <select name="{$field}" class="form-select @error('{$field}') is-invalid @enderror" id="{$field}">
                                        <option value="">Select {$label}</option>
                                        @foreach(\App\Models\\{$relationModel}::all() as \$item)
                                            <option value="{{ \$item->id }}" {{ old('{$field}', \${$modelNameLowerCase}->{$field} ?? '') == \$item->id ? 'selected' : '' }}>
                                                {{ \$item->name ?? \$item->title ?? \$item->id }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <x-field-error field="{$field}" />
                                </div>\n
                            HTML;
                        } 
                        // 3. Smart Boolean Dropdowns (Yes/No)
                        elseif ($isBoolean) {
                            $formFieldsHtml .= <<<HTML
                                <div class="col-md-6 mb-3">
                                    <label for="{$field}" class="form-label">{$label}</label>
                                    <select name="{$field}" class="form-select @error('{$field}') is-invalid @enderror" id="{$field}">
                                        <option value="1" {{ old('{$field}', \${$modelNameLowerCase}->{$field} ?? '1') == '1' ? 'selected' : '' }}>Yes</option>
                                        <option value="0" {{ old('{$field}', \${$modelNameLowerCase}->{$field} ?? '') == '0' ? 'selected' : '' }}>No</option>
                                    </select>
                                    <x-field-error field="{$field}" />
                                </div>\n
                            HTML;
                        }
                        // 4. Standard Text Inputs
                        else {
                            $formFieldsHtml .= <<<HTML
                                <div class="col-md-6 mb-3">
                                    <label for="{$field}" class="form-label">{$label} *</label>
                                    <input type="text" name="{$field}" class="form-control @error('{$field}') is-invalid @enderror" id="{$field}" value="{{ old('{$field}', \${$modelNameLowerCase}->{$field} ?? '') }}" placeholder="e.g. {$label}">
                                    <x-field-error field="{$field}" />
                                </div>\n
                            HTML;
                        }
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
                    '{{ layoutName }}',
                    '{{ ModelName }}',
                    '{{ role }}',
                    '{{ model-plural-kebab }}'
                ],
                [
                    $name,
                    $modelNameLowerCase,
                    $routeName,
                    $viewPrefix,
                    $formFieldsHtml,
                    $layoutName,
                    $name,
                    $role,
                    $modelPluralKebab
                ],
                $stubContent
            );

            \Illuminate\Support\Facades\File::put($destinationPath, $fileContent);
            $this->info("Created: {$destinationPath}");
        } else {
            $this->error("Stub not found: {$stubPath}");
        }
    }

    /**
     * Append generated route resource to routes/web.php.
     */
    protected function appendRoute($name, $role = null, $isApi = false)
    {
        $path = base_path($isApi ? 'routes/api.php' : 'routes/web.php');
        
        // Define the route URL/name in plural kebab-case (e.g., 'posts' or 'blog-posts')
        $routeResourceName = \Illuminate\Support\Str::kebab(\Illuminate\Support\Str::plural($name));

        if ($role) {
            $roleLower = strtolower($role);
            $roleFolder = ucfirst($roleLower); 
            $controller = "\\App\\Http\\Controllers\\{$roleFolder}\\{$name}Controller::class";
            
            $routeType = $isApi ? 'apiResource' : 'resource';
            $middleware = $isApi ? "['auth:sanctum', 'role:{$role}']" : "['role:{$role}']";

            $route = <<<EOT
                \nRoute::group(['prefix' => '{$roleLower}', 'as' => '{$roleLower}.', 'middleware' => {$middleware}], function () {
                    Route::{$routeType}('{$routeResourceName}', {$controller});
                });\n
            EOT;
        } else {
            $controller = "\\App\\Http\\Controllers\\{$name}Controller::class";
            $routeType = $isApi ? 'apiResource' : 'resource';
            $route = "\nRoute::{$routeType}('{$routeResourceName}', {$controller});\n";
        }

        \Illuminate\Support\Facades\File::append($path, $route);
        $this->info("Appended route for {$name} to " . ($isApi ? 'routes/api.php' : 'routes/web.php'));
    }

    // protected function appendRoute($name, $role = null, $isApi = false)
    // {
    //     $path = base_path($isApi ? 'routes/api.php' : 'routes/web.php');
    //     $modelNameLowerCase = strtolower($name);

    //     if ($role) {
    //         $roleLower = strtolower($role);
    //         $roleFolder = ucfirst($roleLower); 
    //         $controller = "\\App\\Http\\Controllers\\{$roleFolder}\\{$name}Controller::class";
            
    //         $routeType = $isApi ? 'apiResource' : 'resource';
    //         $middleware = $isApi ? "['auth:sanctum', 'role:{$role}']" : "['role:{$role}']";

    //         $route = <<<EOT
    //             \nRoute::group(['prefix' => '{$roleLower}', 'as' => '{$roleLower}.', 'middleware' => {$middleware}], function () {
    //                 Route::{$routeType}('{$modelNameLowerCase}', {$controller});
    //             });\n
    //             EOT;
    //     } else {
    //         $controller = "\\App\\Http\\Controllers\\{$name}Controller::class";
    //         $routeType = $isApi ? 'apiResource' : 'resource';
    //         $route = "\nRoute::{$routeType}('{$modelNameLowerCase}', {$controller});\n";
    //     }

    //     \Illuminate\Support\Facades\File::append($path, $route);
    //     $this->info("Appended route for {$name} to " . ($isApi ? 'routes/api.php' : 'routes/web.php'));
    // }

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

    protected function generateDataTableConfigIfNeeded($name, $role)
    {
        $roleLower = $role ? strtolower($role) : 'default';
        $modelPluralKebab = Str::kebab(Str::plural($name));
        $configDir = config_path("datatable/{$roleLower}");
        $configPath = "{$configDir}/{$modelPluralKebab}.php";

        if (File::exists($configPath)) {
            return; // Config already exists
        }

        File::ensureDirectoryExists($configDir);

        $fqcn = "\\App\\Models\\{$name}";
        $hasAttributes = false;

        if (class_exists($fqcn)) {
            $reflection = new \ReflectionClass($fqcn);
            foreach ($reflection->getProperties() as $property) {
                if (!empty($property->getAttributes(\App\Attributes\DataTableColumn::class))) {
                    $hasAttributes = true;
                    break;
                }
            }
        }

        if ($hasAttributes) {
            $this->info("Attributes found on {$name}. Skipping config array generation.");
            return;
        }

        $configArray = "<?php\n\nreturn [\n    'columns' => [\n";
        
        if (class_exists($fqcn)) {
            $model = new $fqcn();
            $columns = \Illuminate\Support\Facades\Schema::getColumnListing($model->getTable());
            $ignoredColumns = ['created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at', 'password'];
            
            foreach ($columns as $col) {
                if (in_array($col, $ignoredColumns)) continue;
                
                $label = Str::title(str_replace('_', ' ', $col));
                $configArray .= "        [\n";
                $configArray .= "            'label' => '{$label}',\n";
                $configArray .= "            'field' => '{$col}',\n";
                if ($col === 'id') {
                    $configArray .= "            'hide' => true,\n";
                    $configArray .= "            'searchable' => false,\n";
                } else {
                    $configArray .= "            'searchable' => true,\n";
                }
                $configArray .= "        ],\n";
            }
        }

        // Append Action Column
        $configArray .= "        [\n";
        $configArray .= "            'label' => 'Action',\n";
        $configArray .= "            'view' => '{$roleLower}.{$modelPluralKebab}.components.actions',\n";
        $configArray .= "        ],\n";
        $configArray .= "    ],\n";
        $configArray .= "    'onRowClick' => [\n        // 'route' => '{$roleLower}.{$modelPluralKebab}.show',\n    ]\n];\n";

        File::put($configPath, $configArray);
        $this->info("Created DataTable config: {$configPath}");
    }

    protected function generateActionComponent($name, $role)
    {
        $rolePath = $role ? strtolower($role) . '/' : '';
        $modelPluralKebab = \Illuminate\Support\Str::kebab(\Illuminate\Support\Str::plural($name));
        $viewPath = resource_path("views/{$rolePath}{$modelPluralKebab}");
        $componentsPath = $viewPath . '/components';

        \Illuminate\Support\Facades\File::ensureDirectoryExists($componentsPath);
        $destinationPath = $componentsPath . '/action.blade.php';

        $stubPath = __DIR__ . "/../Stubs/view.action.stub";

        if (\Illuminate\Support\Facades\File::exists($stubPath)) {
            $stubContent = \Illuminate\Support\Facades\File::get($stubPath);

            $routePrefix = $role ? strtolower($role) . '.' : '';
            $routeName = $routePrefix . $modelPluralKebab; // Generates 'admin.posts'

            $fileContent = str_replace(
                ['{{ routeName }}'],
                [$routeName],
                $stubContent
            );

            \Illuminate\Support\Facades\File::put($destinationPath, $fileContent);
            $this->info("Created Action Component: {$destinationPath}");
        } else {
            $this->error("Stub not found: {$stubPath}");
        }
    }

    protected function generateBaseDataTableFiles()
    {
        // Generate DataTableColumn Attribute
        $this->copyStubToApp('data-table.attribute.stub', app_path('Attributes/DataTableColumn.php'));

        // Generate ColumnGenerator
        $this->copyStubToApp('data-table.column-generator.stub', app_path('DataTable/ColumnGenerator.php'));
        
        // Generate Livewire Component Classes
        $this->copyStubToApp('livewire.data-table.stub', app_path('Livewire/DataTable.php'));
        $this->copyStubToApp('livewire.data-table-filters.stub', app_path('Livewire/DataTableFilters.php'));
        
        // Generate Livewire Views
        $this->copyStubToApp('livewire.view.data-table.stub', resource_path('views/livewire/data-table.blade.php'));
        $this->copyStubToApp('livewire.view.data-table-filters.stub', resource_path('views/livewire/data-table-filters.blade.php'));
    }

    protected function copyStubToApp($stubName, $destinationPath)
    {
        if (!File::exists($destinationPath)) {
            File::ensureDirectoryExists(dirname($destinationPath));
            $stubPath = __DIR__ . '/../Stubs/' . $stubName;
            
            if (File::exists($stubPath)) {
                File::copy($stubPath, $destinationPath);
                $this->info("Generated base DataTable file: {$destinationPath}");
            } else {
                $this->error("Missing expected stub: {$stubPath}");
            }
        }
    }
    

    
}