<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeModuleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:module {name : The name of the module, e.g. Customer, or Logistics/Order}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates a new module directory structure with standard folders and a routes file.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = $this->argument('name');
        $name = str_replace('\\', '/', $name);

        // Ensure the name is properly capitalized (PascalCase)
        $parts = explode('/', $name);
        $parts = array_map(fn ($part) => Str::studly($part), $parts);
        $parsedName = implode('/', $parts);

        $basePath = base_path("src/{$parsedName}");

        if (File::exists($basePath)) {
            $this->error("Module '{$parsedName}' already exists!");

            return static::FAILURE;
        }

        // Create directories
        $directories = [
            'Adapters',
            'Controllers',
            'Events',
            'Exceptions',
            'Models',
            'Requests',
            'Resources',
            'Rules',
            'Services',
            'UseCases',
        ];

        foreach ($directories as $dir) {
            File::makeDirectory("{$basePath}/{$dir}", 0755, true);
            $this->info("Created directory: src/{$parsedName}/{$dir}");
        }

        // Create basic routes.php
        $routesContent = "<?php\n\nuse Illuminate\Support\Facades\Route;\n\n// Route::prefix('api/v1/...')->middleware('auth:api')->group(function () {\n//    \n// });\n";
        File::put("{$basePath}/routes.php", $routesContent);
        $this->info("Created file: src/{$parsedName}/routes.php");

        $this->newLine();
        $this->comment("Module scaffolding for '{$parsedName}' created successfully.");
        $this->info('Routes are discovered automatically: ModuleServiceProvider globs `src/*/routes.php` and `src/*/*/routes.php`.');

        return static::SUCCESS;
    }
}
