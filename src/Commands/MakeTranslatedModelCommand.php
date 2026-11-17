<?php

namespace HibaSabouh\ModelTranslations\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeTranslatedModelCommand extends Command
{
    protected $signature = 'translations:make-model
                            {name : The name of the model}
                            {--m : Create migration}
                            {--r : Create resource controller}
                            {--mr : Create migration and resource controller}';

    protected $description = 'Create a model with its translation model and setup translations';

    public function handle()
    {
        $name = $this->argument('name');
        $createMigration = $this->option('m') || $this->option('mr');
        $createResource = $this->option('r') || $this->option('mr');

        $this->info("Creating translated model: {$name}");

        $this->createMainModel($name, $createMigration, $createResource);

        $translationMigrationPath = $this->createTranslationModel($name, $createMigration);

        if ($createMigration && $translationMigrationPath) {
            $this->modifyTranslationMigration($name, $translationMigrationPath);
        }

        $this->setupMainModel($name);

        $this->info('Translated model created successfully.');
    }

    protected function createMainModel($name, $migration, $resource)
    {
        $options = [];
    
        if ($migration) $options['--migration'] = true;
        if ($resource) $options['--resource'] = true;
    
        $this->call('make:model', [
            'name' => $name,
        ] + $options);
    }

    protected function createTranslationModel($name, $migration)
    {
        $translationName = 'Translations/' . $name . 'Translation';

        $options = [];

        if ($migration) {
            $options['--migration'] = true;
        }

        $before = collect(glob(database_path('migrations/*.php')));

        $this->call('make:model', [
            'name' => $translationName,
        ] + $options);

        $migrationPath = null;
        if ($migration) {
            $after = collect(glob(database_path('migrations/*.php')));

            $newMigration = $after->diff($before)->first();

            if ($newMigration) {
                $newPrefix = date('Y_m_d_His', time() + 1);
                $newFilename = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}/', $newPrefix, basename($newMigration));
                $migrationPath = dirname($newMigration) . '/' . $newFilename;
                rename($newMigration, $migrationPath);
            }
        }

        $this->setupTranslationModel($name);

        return $migrationPath;
    }

    protected function setupTranslationModel($name)
    {
        $path = app_path("Models/Translations/{$name}Translation.php");
    
        if (! file_exists($path)) {
            $this->error("Translation model file not found: {$path}");
            return;
        }
    
        $foreignKey = Str::snake($name) . '_id';
    
        $content = $this->injectIntoClassBody(
            file_get_contents($path),
            "    protected \$fillable = [\n        '{$foreignKey}',\n        'lang',\n    ];"
        );

        file_put_contents($path, $content);
    }

    protected function modifyTranslationMigration($modelName, $migrationPath)
    {
        if (! file_exists($migrationPath)) {
            $this->error('Translation migration not found.');
            return;
        }

        $modelForeignKey = Str::snake($modelName).'_id';

        $content = file_get_contents($migrationPath);

        $schema = "\$table->id();\n" .
          "            \$table->foreignId('{$modelForeignKey}')\n" .
          "                ->constrained()\n" .
          "                ->cascadeOnDelete();\n\n" .
          "            \$table->string('lang');\n" .
          "            \$table->timestamps();";

        $content = preg_replace(
            '/\$table->id\(\);\s*\n\s*\$table->timestamps\(\);/',
            $schema,
            $content
        );

        file_put_contents($migrationPath, $content);
    }

    protected function setupMainModel($name)
    {
        $path = app_path("Models/{$name}.php");
    
        if (! file_exists($path)) {
            $this->error("Model file not found: {$path}");
            return;
        }
    
        $content = file_get_contents($path);
    
        $content = str_replace(
            'use Illuminate\Database\Eloquent\Model;',
            "use Illuminate\Database\Eloquent\Model;\nuse HibaSabouh\ModelTranslations\Traits\HasTranslations;",
            $content
        );
    
        $content = $this->injectIntoClassBody(
            $content,
            "    use HasTranslations;\n\n    protected \$fillable = [];\n\n    protected \$translatable = [];"
        );

        file_put_contents($path, $content);
    }

    /**
     * Insert code at the top of the class body, after any trait use statements.
     */
    protected function injectIntoClassBody(string $content, string $code): string
    {
        // Laravel 11+ stubs leave a "//" placeholder in an empty class body.
        $content = preg_replace('/(class\s+\w+[^{]*\{)\s*\/\/[ \t]*(?=\R)/', '$1', $content, 1);

        $afterTraits = '/class\s+\w+[^{]*\{[\s\S]*?^[ \t]*use\s+[^;]+;/m';

        if (preg_match($afterTraits, $content)) {
            $separator = str_starts_with(ltrim($code), 'use ') ? "\n" : "\n\n";

            return preg_replace_callback($afterTraits, fn ($m) => $m[0] . $separator . $code, $content, 1);
        }

        return preg_replace_callback('/class\s+\w+[^{]*\{/', fn ($m) => $m[0] . "\n" . $code, $content, 1);
    }
}