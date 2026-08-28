<?php

namespace NirikshaOccesstech\CrudGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class DeleteCrudCommand extends Command
{
    protected $signature = 'make:crud-delete {name : The name of the model}';

    protected $description = 'Delete all generated CRUD files, views, configs, and routes for a model';

    public function handle()
    {
        $name = ucfirst($this->argument('name'));
        $modelNameLowerCase = strtolower($name);
        $modelPluralKebab = Str::kebab(Str::plural($name));

        if (!$this->confirm("Are you sure you want to delete all generated CRUD layers for '{$name}'?")) {
            $this->info('Operation cancelled.');
            return 0;
        }

        // 1. Delete Controller (Search default and subfolders like Admin)
        $this->deleteFile(app_path("Http/Controllers/{$name}Controller.php"));
        
        $controllersDir = app_path('Http/Controllers');
        if (File::exists($controllersDir)) {
            foreach (File::directories($controllersDir) as $subDir) {
                $roleControllerPath = "{$subDir}/{$name}Controller.php";
                $this->deleteFile($roleControllerPath);
            }
        }

        // 2. Delete Services & Repositories
        $this->deleteFile(app_path("Repositories/Contracts/{$name}RepositoryInterface.php"));
        $this->deleteFile(app_path("Repositories/Eloquent/{$name}Repository.php"));
        $this->deleteFile(app_path("Services/Contracts/{$name}ServiceInterface.php"));
        $this->deleteFile(app_path("Services/Implementation/{$name}Service.php"));

        // 3. Delete Form Requests
        $this->deleteFile(app_path("Http/Requests/Store{$name}Request.php"));
        $this->deleteFile(app_path("Http/Requests/Update{$name}Request.php"));

        $requestsDir = app_path('Http/Requests');
        if (File::exists($requestsDir)) {
            foreach (File::directories($requestsDir) as $subDir) {
                $roleStoreRequest = "{$subDir}/Store{$name}Request.php";
                $roleUpdateRequest = "{$subDir}/Update{$name}Request.php";
                
                $this->deleteFile($roleStoreRequest);
                $this->deleteFile($roleUpdateRequest);
            }
        }

        // 4. Delete View Folders (Both standard lowercase and new kebab-plural for DataTables)
        $viewsDir = resource_path('views');
        $this->deleteDirectory(resource_path("views/{$modelNameLowerCase}"));
        $this->deleteDirectory(resource_path("views/{$modelPluralKebab}"));

        if (File::exists($viewsDir)) {
            foreach (File::directories($viewsDir) as $subDir) {
                $roleViewDir = "{$subDir}/{$modelNameLowerCase}";
                $roleDataTableDir = "{$subDir}/{$modelPluralKebab}";
                
                if (File::isDirectory($roleViewDir)) {
                    $this->deleteDirectory($roleViewDir);
                }
                if (File::isDirectory($roleDataTableDir)) {
                    $this->deleteDirectory($roleDataTableDir);
                }
            }
        }

        // 5. Delete DataTable Config Files
        $this->deleteFile(config_path("datatable/default/{$modelPluralKebab}.php"));
        $configDir = config_path('datatable');
        if (File::exists($configDir)) {
            foreach (File::directories($configDir) as $subDir) {
                $roleConfigPath = "{$subDir}/{$modelPluralKebab}.php";
                $this->deleteFile($roleConfigPath);
            }
        }

        // 6. Remove Appended Routes from routes/web.php
        $this->removeAppendedRoute($name);

        $this->info("CRUD cleanup for '{$name}' completed successfully.");
        return 0;
    }

    protected function deleteFile($path)
    {
        if (File::exists($path)) {
            File::delete($path);
            $this->warn("Deleted file: {$path}");
        }
    }

    protected function deleteDirectory($path)
    {
        if (File::isDirectory($path)) {
            File::deleteDirectory($path);
            $this->warn("Deleted directory: {$path}");
        }
    }

    protected function removeAppendedRoute($name)
    {
        $webRoutePath = base_path('routes/web.php');
        if (!File::exists($webRoutePath)) {
            return;
        }

        $content = File::get($webRoutePath);
        $modelNameLowerCase = strtolower($name);

        // Remove resource route line
        $pattern = "/Route::resource\('{$modelNameLowerCase}', [^;]+;\n?/";
        $content = preg_replace($pattern, '', $content);

        // Remove empty role groups if any remain
        $groupPattern = "/Route::group\(\['prefix' => '[^']+', 'as' => '[^']+', 'middleware' => \['role:[^']+'\]\], function \(\) \{\s*\}\);\n?/";
        $content = preg_replace($groupPattern, '', $content);

        File::put($webRoutePath, $content);
        $this->warn("Removed routes for '{$name}' from routes/web.php");
    }
}