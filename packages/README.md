# Laravel CRUD Generator by Niriksha Occesstech

A powerful Laravel Artisan package that instantly scaffolds a clean, scalable Repository-Service architecture with built-in transaction safety, Form Requests, and robust error handling. Stop writing boilerplate code and focus entirely on business logic.

## Features

- **Clean Architecture:** Automatically generates Service and Repository layers with Interface Contracts.
- **Safe Database Transactions:** Built-in error logging, automatic rollbacks, and retry wrappers using `HandlesSafeTransactions`.
- **Form Requests:** Scaffolds and injects custom `Store` and `Update` Form Requests.
- **Controllers & Views:** Creates resource controllers and clean Blade view layouts.
- **Route Injection:** Automatically appends `Route::resource()` entries to your `routes/web.php`.
- **Stub Customization:** Fully publishable stubs so you can adapt the generated code templates to your team's exact standards.
- **Selective Generation:** Generate everything at once or target specific layers using flexible command flags.

## Requirements

- PHP 8.3+
- Laravel 11.x / 12.x / 13.x

## Installation

Because this package is hosted on GitHub, you can require it directly via Composer.

### Step 1: Add the Repository to your Project's `composer.json`
Open your Laravel project's `composer.json` and add your GitHub repository source under the `"repositories"` block:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "[https://github.com/Ravikumar15081987/crud-generator.git](https://github.com/Ravikumar15081987/crud-generator.git)"
    }
]

### Step 2: Require the Package
Run the following command in your terminal inside your project root:

Bash
composer require nirikshaoccesstech/crud-generator:dev-main
Usage
Generate a complete CRUD architecture for any model (e.g., Product) with a single command:

Bash
php artisan make:crud Product --all
Available Options & Flags
You can selectively generate specific layers using individual flags:

-c or --controller : Create the resource controller

-s or --service : Create the service layer (Contract & Implementation) with safe transactions

-r or --repository : Create the repository layer (Contract & Implementation)

-w or --views : Create the Blade views (Create & Edit)

-Q or --requests : Create the Store and Update Form Requests

-R or --route : Automatically append resource route to routes/web.php

-a or --all : Generate all layers and append routes instantly

Example: Generate only the Service and Repository layers for a model:

Bash
php artisan make:crud Product --service --repository
Customizing Stubs (Stub Publishing)
If you want to modify how the generated files look (e.g., changing namespaces, adding custom layout templates, or altering code formatting), you can publish the package stubs directly into your application:

Bash
php artisan vendor:publish --tag=crud-generator-stubs
This will copy all blueprints into resources/vendor/crud-generator/stubs/. Your generator will automatically detect and use your customized stubs moving forward!

Auto-Binding Interfaces (Recommended Setup)
To avoid manually binding generated Interfaces to their Implementations inside a Service Provider every time, create an Auto-Binding Service Provider in your application:

Run:

Bash
php artisan make:provider CrudBindingServiceProvider
Add this dynamic binding logic to app/Providers/CrudBindingServiceProvider.php:

PHP
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\File;

class CrudBindingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->autoBind('Repositories/Contracts', 'Repositories\Contracts', 'Repositories\Eloquent');$this->autoBind('Services/Contracts', 'Services\Contracts', 'Services\Implementation');
    }

    private function autoBind($path, $interfaceNamespace,$implNamespace): void
    {
        $contractsPath = app_path($path);
        if (!File::exists($contractsPath)) return;

        foreach (File::files($contractsPath) as $file) {$interfaceName = $file->getFilenameWithoutExtension();$interface = "App\\{$interfaceNamespace}\\{$interfaceName}";
            $implementationName = str_replace('Interface', '', $interfaceName);$implementation = "App\\{$implNamespace}\\{$implementationName}";

            if (class_exists($implementation)) {$this->app->bind($interface,$implementation);
            }
        }
    }
}
Finally, register this provider in your bootstrap/providers.php file.