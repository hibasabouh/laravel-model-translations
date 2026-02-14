# Laravel Model Translations

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

A simple Laravel package to handle **database-driven model translations** using separate translation tables.  
Add the `HasTranslations` trait to any Eloquent model to store and retrieve translations with locale-aware magic accessors and convenient CRUD helpers.

---

## Features

- Define translatable attributes on your models with a `$translatable` property
- Auto-resolve the translation model by convention, or override it explicitly
- Automatically eager load translations via a configurable global scope
- Locale-aware magic accessors with configurable fallback strategy
- Access all translations for an attribute at once via `{attribute}_translations`
- Convenient CRUD methods: `createWithTranslations()`, `updateWithTranslations()`, `firstOrCreateWithTranslations()`, `updateOrCreateWithTranslations()`
- Query scopes to filter by translation: `whereTranslation()`, `whereAnyTranslation()`, `orWhereTranslation()`, `orWhereAnyTranslation()`

---

## Installation

### 1. Require the package (local development)

In your Laravel app, add the package repository to `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "../laravel-model-translations"
    }
]
```

Then require the package:

```bash
composer require hibasabouh/laravel-model-translations:@dev
```

### 2. Publish configuration

```bash
php artisan vendor:publish --tag=translatable-config
```

This will publish the config file to `config/translatable.php`.

---

## Setup

### 1. Create the translation table

Each translatable model needs a corresponding translation table. For example, for a `states` table:

```php
Schema::create('state_translations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('state_id')->constrained()->cascadeOnDelete();
    $table->string('lang', 10);
    $table->string('name');
    $table->timestamps();

    $table->unique(['state_id', 'lang']);
});
```

### 2. Create the translation model

```php
namespace App\Models\Translations;

use Illuminate\Database\Eloquent\Model;

class StateTranslation extends Model
{
    protected $fillable = ['state_id', 'lang', 'name'];
}
```

> By convention, the trait looks for the translation model in the `Translations` sub-namespace of the parent model's namespace, named `{Model}Translation`. A `App\Models\State` model resolves to `App\Models\Translations\StateTranslation`.

### 3. Use the trait on your model

```php
namespace App\Models;

use HibaSabouh\ModelTranslations\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Model;

class State extends Model
{
    use HasTranslations;

    protected $fillable = ['country_id', 'code', 'status'];

    protected array $translatable = ['name'];
}
```

---

## Usage

### Creating a model with translations

Pass translatable attributes as arrays keyed by language code:

```php
State::createWithTranslations([
    'country_id' => 1,
    'code'       => 'CA',
    'status'     => 'active',
    'name'       => [
        'en' => 'California',
        'fr' => 'Californie',
    ],
]);
```

### Updating a model with translations

```php
$state->updateWithTranslations([
    'status' => 'inactive',
    'name'   => [
        'en' => 'California (updated)',
        'fr' => 'Californie (mise à jour)',
    ],
]);
```

Existing translations are updated via `updateOrCreate` per language. Translations for languages not included in the call are left untouched.

### First or create with translations

```php
State::firstOrCreateWithTranslations(
    ['code' => 'CA'],           // match attributes
    [
        'country_id' => 1,
        'name' => [
            'en' => 'California',
            'fr' => 'Californie',
        ],
    ]
);
```

If a match is found, the existing model is returned as-is. Translations are only created on the first insert.

### Update or create with translations

```php
State::updateOrCreateWithTranslations(
    ['code' => 'CA'],           // match attributes
    [
        'country_id' => 1,
        'name' => [
            'en' => 'California',
            'fr' => 'Californie',
        ],
    ]
);
```

### Reading translations

Translatable attributes are accessed directly by name. The trait's magic accessor returns the value for the current application locale:

```php
app()->setLocale('en');
echo $state->name; // "California"

app()->setLocale('fr');
echo $state->name; // "Californie"
```

To retrieve all translations for an attribute at once, use the `{attribute}_translations` accessor:

```php
$state->name_translations;
// ['en' => 'California', 'fr' => 'Californie']
```

### Accessing the translations relationship

The `translations()` relation is a standard `HasMany` and can be used like any Eloquent relation:

```php
$state->translations;                                         // all translations
$state->translations()->where('lang', 'en')->first();         // filter by locale
```

### Querying by translation

Four query scopes are available to filter models by their translated values.

**`whereTranslation()`** — filters by a specific locale (defaults to the current app locale):

```php
// Exact match in current locale
State::whereTranslation('name', 'California')->get();

// With operator, current locale
State::whereTranslation('name', 'like', '%land%')->get();

// Explicit locale
State::whereTranslation('name', 'like', '%land%', 'fr')->get();
```

**`whereAnyTranslation()`** — filters across all locales:

```php
// Match in any locale
State::whereAnyTranslation('name', 'California')->get();

// With operator, any locale
State::whereAnyTranslation('name', 'like', '%cali%')->get();
```

**`orWhereTranslation()` and `orWhereAnyTranslation()`** — OR variants for chaining:

```php
State::whereTranslation('name', 'California')
    ->orWhereTranslation('name', 'Californie')
    ->get();

State::where('status', 'active')
    ->orWhereAnyTranslation('name', 'like', '%land%')
    ->get();
```

---

## Overriding the translation model

If your translation model does not follow the default naming convention, define a `$translationModel` property on your model:

```php
class State extends Model
{
    use HasTranslations;

    protected string $translationModel = \App\Models\CustomStateTranslation::class;

    protected array $translatable = ['name'];
}
```

---

## Configuration

After publishing, edit `config/translatable.php`:

```php
return [
    'auto_load' => true,
    'fallback'  => 'app',
];
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `auto_load` | `bool` | `true` | Automatically eager loads the `translations` relation on every query via a global scope. Set to `false` to load translations manually. |
| `fallback` | `string\|null` | `'app'` | Controls what is returned when no translation exists for the current locale. See fallback strategies below. |

### Fallback strategies

| Value | Behaviour |
|-------|-----------|
| `null` | Returns `null` — no fallback |
| `'app'` | Falls back to `config('app.fallback_locale')` |
| `'first'` | Falls back to the first available translation regardless of locale |

---

## License

The MIT License (MIT). Please see the [License File](LICENSE) for more information.