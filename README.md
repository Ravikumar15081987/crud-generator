🚀 Laravel Advanced CRUD Generator
A powerful, developer-friendly package to instantly scaffold complete, enterprise-grade CRUD architecture in Laravel. This package generates Controllers, Service layers, Repository layers, smart Form Requests, Blade Views (or API JSON responses), and Routes, fully integrated with role-based namespaces.

⚠️ Prerequisites
This package relies on two specific dependencies for role management and UI notifications:

🔒 Spatie Laravel Permission (spatie/laravel-permission)

🍞 ToastMagic (devrabiul/laravel-toaster-magic)

Note: Make sure your Laravel project has these installed and configured before generating CRUD layers.

📦 Installation
Install the package via Composer:

Bash
composer require nirikshaoccesstech/crud-generator --dev
(If the package is in a private repository, ensure your composer.json is configured with the correct VCS repository URL).

🛠️ Core Usage
The primary command to scaffold a new CRUD module is make:crud.

1️⃣ Prepare Your Model
Before running the generator, ensure your model exists and has its $fillable and $casts properties defined. The generator reads these to automatically build smart validation rules and intelligent HTML forms.

PHP
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Property extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'category_id',
        'image',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
2️⃣ Run the Generator
To generate all layers (Controller, Service, Repository, Requests, Views, Routes), run:

Bash
php artisan make:crud Property --all
💬 Interactive Prompts:
During execution, the console will ask two questions:

👤 Role Selection: Select a role (e.g., Admin, Manager, or None). If you select a role, the package will group the Controllers and Requests into a dedicated namespace (e.g., App\Http\Controllers\Admin) and wrap the generated route in role middleware.

🎨 Layout Name: Provide the name of the Blade layout your views should extend (e.g., layouts.app).

⚙️ Command Options
You can selectively generate specific layers by passing targeted flags instead of --all:

📄 --controller: Generates only the Controller.

⚙️ --service: Generates the Service Interface and Implementation.

🗄️ --repository: Generates the Repository Interface and Implementation.

🖥️ --views: Generates the Blade views (index, create, edit, show, _form).

🛡️ --requests: Generates Store and Update Form Requests.

🔗 --route: Appends the resource route to routes/web.php.

🔌 --api: Skips Blade views, generates an API-formatted Controller (returning JsonResponse), and appends to routes/api.php.

🖼️ --media: Adds Spatie MediaLibrary placeholders if your model uses the InteractsWithMedia trait.

💡 Example: Headless API Generation

Bash
php artisan make:crud Project --api --all
This skips all Blade scaffolding and perfectly structures the module for REST API consumption.

🧠 Intelligent Features
🎯 Smart Validation Rules
The package reads your model's $casts and naming conventions to generate accurate rules in App\Http\Requests.

🟢 If a field is cast to boolean, or starts with is_ / has_, it applies 'boolean'.

🔢 If a field ends in _id, it applies 'integer'.

📝 Standard fields receive 'string|max:255', but the length limit is dynamically removed for text areas like description or content.

🔔 Custom BaseRequest & UiNotify Integration
Generated form requests extend a custom BaseRequest class (automatically generated if missing). This class catches ValidationException and AuthorizationException, routing them through UiNotify (ToastMagic) to flash beautiful, non-intrusive UI error toasts without flooding your application logs with simple user input errors.

✨ Smart Form Generation
When generating Blade views, the _form.blade.php file applies logic based on your $fillable array:

🔗 Foreign Keys: Fields ending in _id (e.g., category_id) automatically generate a <select> dropdown populated with App\Models\Category::all().

📁 File Uploads: Fields named image, document, or avatar automatically generate <input type="file">.

🔴 UI Components: If missing, the package automatically publishes a reusable <x-field-error> Blade component to handle inline validation states elegantly.

🗑️ Rollback & Cleanup
If you make a mistake or want to remove a generated module completely, use the delete command:

Bash
php artisan make:crud-delete Property
This command will prompt for confirmation and cleanly erase:

❌ The Controller (including inside role-based subdirectories).

❌ The Service Interface and Implementation.

❌ The Repository Interface and Implementation.

❌ The Form Requests (Store and Update).

❌ The dedicated View directory.

❌ The appended lines in routes/web.php or routes/api.php.