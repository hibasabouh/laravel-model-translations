<?php

afterEach(function () {
    @unlink(app_path('Models/Product.php'));
    @unlink(app_path('Models/Translations/ProductTranslation.php'));

    foreach (glob(database_path('migrations/*product*')) as $file) {
        @unlink($file);
    }

    @unlink(base_path('stubs/model.stub'));
    @rmdir(base_path('stubs'));
});

it('injects the trait and properties into the model', function () {
    $this->artisan('translations:make-model', ['name' => 'Product']);

    $content = file_get_contents(app_path('Models/Product.php'));

    expect($content)
        ->toContain('use HibaSabouh\ModelTranslations\Traits\HasTranslations;')
        ->toMatch('/^    use HasTranslations;$/m')
        ->toContain('protected $fillable = []')
        ->toContain('protected $translatable = []')
        ->not->toMatch('/^\s*\/\/\s*$/m');
});

it('keeps existing traits when the model stub already uses one', function () {
    @mkdir(base_path('stubs'));
    file_put_contents(base_path('stubs/model.stub'), <<<'STUB'
<?php

namespace {{ namespace }};

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class {{ class }} extends Model
{
    /** @use HasFactory<\Database\Factories\ExampleFactory> */
    use HasFactory;
}
STUB);

    $this->artisan('translations:make-model', ['name' => 'Product']);

    expect(file_get_contents(app_path('Models/Product.php')))
        ->toMatch('/use HasFactory;\n    use HasTranslations;\n\n    protected \$fillable = \[\];/')
        ->and(file_get_contents(app_path('Models/Translations/ProductTranslation.php')))
        ->toMatch('/use HasFactory;\n\n    protected \$fillable = \[/');
});

it('creates the main model file', function () {
    $this->artisan('translations:make-model', ['name' => 'Product'])
        ->assertSuccessful();

    expect(app_path('Models/Product.php'))->toBeFile();
});

it('adds HasTranslations trait to main model', function () {
    $this->artisan('translations:make-model', ['name' => 'Product']);

    expect(file_get_contents(app_path('Models/Product.php')))
        ->toContain('use HasTranslations')
        ->toContain('protected $translatable = []');
});

it('creates the translation model file', function () {
    $this->artisan('translations:make-model', ['name' => 'Product']);

    expect(app_path('Models/Translations/ProductTranslation.php'))->toBeFile();
});

it('adds fillable to translation model', function () {
    $this->artisan('translations:make-model', ['name' => 'Product']);

    expect(file_get_contents(app_path('Models/Translations/ProductTranslation.php')))
        ->toContain("'product_id'")
        ->toContain("'lang'");
});

it('creates migrations when --m is passed', function () {
    $before = glob(database_path('migrations/*.php'));

    $this->artisan('translations:make-model', ['name' => 'Product', '--m' => true]);

    $after = glob(database_path('migrations/*.php'));
    expect(count($after))->toBe(count($before) + 2); // main + translation
});

it('prefills the translation migration with the correct schema', function () {
    $this->artisan('translations:make-model', ['name' => 'Product', '--m' => true]);

    $migration = collect(glob(database_path('migrations/*.php')))
        ->first(fn ($f) => str_contains($f, 'product_translation'));

    expect(file_get_contents($migration))
        ->toContain('product_id')
        ->toContain("cascadeOnDelete")
        ->toContain("'lang'");
});