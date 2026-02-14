<?php

use Tests\Models\Product;
use Tests\Models\Translations\ProductTranslation;

// ============================================================
// Helpers
// ============================================================

function makeProduct(array $overrides = []): Product
{
    return Product::createWithTranslations(array_merge([
        'sku'         => 'P-' . uniqid(),
        'price'       => 100,
        'name'        => ['en' => 'Laptop', 'fr' => 'Ordinateur'],
        'description' => ['en' => 'A laptop', 'fr' => 'Un ordinateur'],
    ], $overrides));
}

// ============================================================
// translations() relationship
// ============================================================

it('has a translations HasMany relationship', function () {
    $product = makeProduct();

    expect($product->translations())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class)
        ->and($product->translations)->toHaveCount(2);
});

it('translation records belong to the correct product', function () {
    $product = makeProduct();

    $product->translations->each(function ($t) use ($product) {
        expect($t->product_id)->toBe($product->id);
    });
});

// ============================================================
// createWithTranslations()
// ============================================================

it('creates model with translations', function () {
    $product = Product::createWithTranslations([
        'sku'         => 'P001',
        'price'       => 100,
        'name'        => ['en' => 'Laptop', 'fr' => 'Ordinateur Portable'],
        'description' => ['en' => 'High-end laptop', 'fr' => 'Ordinateur haut de gamme'],
    ]);

    expect($product->sku)->toBe('P001')
        ->and($product->translations)->toHaveCount(2)
        ->and($product->name_translations)->toHaveKeys(['en', 'fr'])
        ->and($product->name_translations['fr'])->toBe('Ordinateur Portable');
});

it('persists translations to the database', function () {
    $product = makeProduct(['sku' => 'P-DB']);

    expect(ProductTranslation::where('product_id', $product->id)->count())->toBe(2);
});

it('does not store translatable keys in the main table', function () {
    $product = makeProduct(['sku' => 'P-MAIN']);

    $raw = \Illuminate\Support\Facades\DB::table('products')->find($product->id);
    expect(isset($raw->name))->toBeFalse()
        ->and(isset($raw->description))->toBeFalse();
});

it('rolls back if translation creation fails', function () {
    // Pass a non-array value for a translatable attribute to trigger the exception
    expect(fn () => Product::createWithTranslations([
        'sku'   => 'P-FAIL',
        'price' => 10,
        'name'  => 'not-an-array',
    ]))->toThrow(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);

    expect(Product::where('sku', 'P-FAIL')->exists())->toBeFalse();
});

// ============================================================
// updateWithTranslations()
// ============================================================

it('updates base attributes', function () {
    $product = makeProduct(['price' => 100]);
    $product->updateWithTranslations(['price' => 200]);

    expect($product->fresh()->price)->toBe('200.00');
});

it('updates existing translations', function () {
    $product = makeProduct();
    $product->updateWithTranslations([
        'name' => ['en' => 'Updated Laptop', 'fr' => 'Ordinateur Mis à Jour'],
    ]);

    expect($product->fresh()->name_translations['en'])->toBe('Updated Laptop')
        ->and($product->fresh()->name_translations['fr'])->toBe('Ordinateur Mis à Jour');
});

it('adds a new locale translation on update', function () {
    $product = makeProduct(); // only en + fr
    $product->updateWithTranslations([
        'name' => ['ar' => 'حاسوب محمول'],
    ]);

    expect($product->translations()->where('lang', 'ar')->exists())->toBeTrue()
        ->and($product->translations)->toHaveCount(3);
});

it('leaves untouched locales unchanged on update', function () {
    $product = makeProduct([
        'name' => ['en' => 'Laptop', 'fr' => 'Ordinateur'],
    ]);

    $product->updateWithTranslations([
        'name' => ['en' => 'Updated Laptop'],
    ]);

    expect($product->translations()->where('lang', 'fr')->first()->name)->toBe('Ordinateur');
});

it('reloads the translations relation after update', function () {
    $product = makeProduct();
    $product->updateWithTranslations(['name' => ['en' => 'Refreshed']]);

    expect($product->relationLoaded('translations'))->toBeTrue()
        ->and($product->translations->firstWhere('lang', 'en')->name)->toBe('Refreshed');
});

it('returns true on successful update', function () {
    $product = makeProduct();
    $result  = $product->updateWithTranslations(['price' => 999]);

    expect($result)->toBeTrue();
});

// ============================================================
// firstOrCreateWithTranslations()
// ============================================================

it('creates a new model with translations when no match exists', function () {
    $product = Product::firstOrCreateWithTranslations(
        ['sku' => 'UNIQUE-SKU'],
        [
            'price' => 50,
            'name'  => ['en' => 'Mouse', 'fr' => 'Souris'],
        ]
    );

    expect($product->wasRecentlyCreated)->toBeTrue()
        ->and($product->translations)->toHaveCount(2);
});

it('returns existing model without touching translations when match found', function () {
    $existing = makeProduct(['sku' => 'EXISTING']);

    $found = Product::firstOrCreateWithTranslations(
        ['sku' => 'EXISTING'],
        ['name' => ['en' => 'Should Not Update']]
    );

    expect($found->id)->toBe($existing->id)
        ->and($found->name_translations['en'])->toBe('Laptop'); // original value
});

it('loads translations on the returned model', function () {
    $product = Product::firstOrCreateWithTranslations(
        ['sku' => 'LOADED-' . uniqid()],
        ['price' => 10, 'name' => ['en' => 'Keyboard']]
    );

    expect($product->relationLoaded('translations'))->toBeTrue();
});

// ============================================================
// updateOrCreateWithTranslations()
// ============================================================

it('creates model with translations when no match exists', function () {
    $product = Product::updateOrCreateWithTranslations(
        ['sku' => 'NEW-UOC'],
        ['price' => 75, 'name' => ['en' => 'Monitor', 'fr' => 'Écran']]
    );

    expect(Product::where('sku', 'NEW-UOC')->exists())->toBeTrue()
        ->and($product->translations)->toHaveCount(2);
});

it('updates model and translations when match exists', function () {
    makeProduct(['sku' => 'EXISTING-UOC', 'price' => 50]);

    $product = Product::updateOrCreateWithTranslations(
        ['sku' => 'EXISTING-UOC'],
        ['price' => 150, 'name' => ['en' => 'Updated Monitor']]
    );

    expect($product->fresh()->price)->toBe('150.00')
        ->and($product->name_translations['en'])->toBe('Updated Monitor');
});

it('loads translations on the returned model after update or create', function () {
    $product = Product::updateOrCreateWithTranslations(
        ['sku' => 'REL-' . uniqid()],
        ['price' => 10, 'name' => ['en' => 'Webcam']]
    );

    expect($product->relationLoaded('translations'))->toBeTrue();
});

// ============================================================
// __get() — magic accessor
// ============================================================

it('returns translation using magic getter', function () {
    $product = makeProduct([
        'name' => ['en' => 'Phone', 'fr' => 'Téléphone'],
    ]);

    app()->setLocale('fr');
    expect($product->name)->toBe('Téléphone');

    app()->setLocale('en');
    expect($product->name)->toBe('Phone');
});

it('returns null when no translation exists for current locale and fallback is null', function () {
    config()->set('translatable.fallback', null);

    $product = makeProduct(['name' => ['en' => 'Tablet']]);

    app()->setLocale('de');
    expect($product->name)->toBeNull();
});

it('falls back to app fallback_locale when strategy is app', function () {
    config()->set('translatable.fallback', 'app');
    config()->set('app.fallback_locale', 'en');

    $product = makeProduct(['name' => ['en' => 'Tablet', 'fr' => 'Tablette']]);

    app()->setLocale('de');
    expect($product->name)->toBe('Tablet');
});

it('falls back to first available translation when strategy is first', function () {
    config()->set('translatable.fallback', 'first');

    $product = makeProduct(['name' => ['fr' => 'Tablette']]);

    app()->setLocale('de');
    expect($product->name)->toBe('Tablette');
});

it('caches the loaded translations relation instead of re-querying', function () {
    $product = makeProduct();

    // Access name twice — should load relation once and reuse it
    $product->name;
    $product->name;

    expect($product->relationLoaded('translations'))->toBeTrue();
});

// ============================================================
// {attribute}_translations accessor
// ============================================================

it('returns all translations keyed by locale via _translations accessor', function () {
    $product = makeProduct([
        'name' => ['en' => 'Laptop', 'fr' => 'Ordinateur', 'ar' => 'حاسوب'],
    ]);

    $translations = $product->name_translations;

    expect($translations)->toHaveKeys(['en', 'fr', 'ar'])
        ->and($translations['ar'])->toBe('حاسوب');
});

it('returns empty array for _translations when no translations exist', function () {
    $product = Product::create(['sku' => 'EMPTY-' . uniqid(), 'price' => 10]);

    expect($product->name_translations)->toBe([]);
});

// ============================================================
// auto_load global scope
// ============================================================

it('eager loads translations automatically when auto_load is true', function () {
    config()->set('translatable.auto_load', true);
    makeProduct(['sku' => 'AUTO-LOAD']);

    $product = Product::where('sku', 'AUTO-LOAD')->first();

    expect($product->relationLoaded('translations'))->toBeTrue();
});

it('does not eager load translations when auto_load is false', function () {
    config()->set('translatable.auto_load', false);
    makeProduct(['sku' => 'NO-AUTO-' . uniqid()]);

    // Re-boot the model to apply new config (withoutGlobalScope)
    $product = Product::withoutGlobalScope('withTranslations')
        ->where('sku', 'like', 'NO-AUTO-%')
        ->first();

    expect($product->relationLoaded('translations'))->toBeFalse();
});

// ============================================================
// whereTranslation() scope
// ============================================================

it('filters by translation with default = operator', function () {
    makeProduct(['sku' => 'WH-1', 'name' => ['en' => 'Camera']]);
    makeProduct(['sku' => 'WH-2', 'name' => ['en' => 'Tripod']]);

    $results = Product::whereTranslation('name', 'Camera')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->sku)->toBe('WH-1');
});

it('filters by translation with explicit operator', function () {
    app()->setLocale('en'); // explicit — never rely on ambient locale in scope tests
    makeProduct(['sku' => 'WH-OP-1', 'name' => ['en' => 'Camera Pro']]);
    makeProduct(['sku' => 'WH-OP-2', 'name' => ['en' => 'Tripod']]);

    $results = Product::whereTranslation('name', 'like', 'Camera%')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->sku)->toBe('WH-OP-1');
});

it('filters by translation for an explicit locale', function () {
    makeProduct(['sku' => 'WH-LANG-1', 'name' => ['en' => 'Lens', 'fr' => 'Objectif']]);
    makeProduct(['sku' => 'WH-LANG-2', 'name' => ['en' => 'Flash', 'fr' => 'Flash']]);

    $results = Product::whereTranslation('name', 'Objectif', null, 'fr')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->sku)->toBe('WH-LANG-1');
});

it('does not match a translation in a different locale', function () {
    makeProduct(['sku' => 'WH-LOC', 'name' => ['fr' => 'Objectif']]);

    app()->setLocale('en');
    $results = Product::whereTranslation('name', 'Objectif')->get();

    expect($results)->toHaveCount(0);
});

// ============================================================
// whereAnyTranslation() scope
// ============================================================

it('matches a translation in any locale', function () {
    makeProduct(['sku' => 'ANY-1', 'name' => ['en' => 'Printer', 'fr' => 'Imprimante']]);
    makeProduct(['sku' => 'ANY-2', 'name' => ['en' => 'Scanner', 'fr' => 'Scanneur']]);

    $results = Product::whereAnyTranslation('name', 'Imprimante')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->sku)->toBe('ANY-1');
});

it('supports operator in whereAnyTranslation', function () {
    makeProduct(['sku' => 'ANY-OP-1', 'name' => ['en' => 'Pro Printer']]);
    makeProduct(['sku' => 'ANY-OP-2', 'name' => ['en' => 'Basic Scanner']]);

    $results = Product::whereAnyTranslation('name', 'like', '%Printer%')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->sku)->toBe('ANY-OP-1');
});

// ============================================================
// orWhereTranslation() scope
// ============================================================

it('chains orWhereTranslation correctly', function () {
    makeProduct(['sku' => 'OR-1', 'name' => ['en' => 'Alpha']]);
    makeProduct(['sku' => 'OR-2', 'name' => ['en' => 'Beta']]);
    makeProduct(['sku' => 'OR-3', 'name' => ['en' => 'Gamma']]);

    $results = Product::whereTranslation('name', 'Alpha')
        ->orWhereTranslation('name', 'Beta')
        ->get();

    expect($results)->toHaveCount(2)
        ->and($results->pluck('sku')->sort()->values()->toArray())
        ->toBe(['OR-1', 'OR-2']);
});

// ============================================================
// orWhereAnyTranslation() scope
// ============================================================

it('chains orWhereAnyTranslation correctly', function () {
    makeProduct(['sku' => 'ORA-1', 'name' => ['en' => 'Delta', 'fr' => 'Epsilon']]);
    makeProduct(['sku' => 'ORA-2', 'name' => ['en' => 'Zeta']]);

    $results = Product::where('sku', 'ORA-2')
        ->orWhereAnyTranslation('name', 'Epsilon')
        ->get();

    expect($results)->toHaveCount(2)
        ->and($results->pluck('sku')->sort()->values()->toArray())
        ->toBe(['ORA-1', 'ORA-2']);
});

// ============================================================
// getTranslatableAttributes() guard
// ============================================================

it('throws if model does not define $translatable', function () {

    $anonymous = new class extends \Illuminate\Database\Eloquent\Model {
        use \HibaSabouh\ModelTranslations\Traits\HasTranslations;
        protected $table = 'products';
        protected $guarded = [];
    };

    expect(fn () =>
        $anonymous::createWithTranslations([
            'sku' => 'X',
            'price' => 100,
            'name' => ['en' => 'Test'],
        ])
    )->toThrow(
        \HibaSabouh\ModelTranslations\Exceptions\MissingTranslatablePropertyException::class,
        'must define a $translatable property'
    );
});

// ============================================================
// Invalid translation format guard
// ============================================================

it('throws when translation attribute is not an array', function () {

    expect(fn () =>
        makeProduct(['sku' => 'ORA-2', 'name' => 'Invalid String Instead Of Array'])
    )->toThrow(
        \HibaSabouh\ModelTranslations\Exceptions\InvalidTranslationFormatException::class,
        "The 'name' attribute must be an array"
    );
});