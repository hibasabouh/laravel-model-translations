<?php

namespace HibaSabouh\ModelTranslations\Traits;

use HibaSabouh\ModelTranslations\Exceptions\InvalidTranslationFormatException;
use HibaSabouh\ModelTranslations\Exceptions\MissingTranslatablePropertyException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * HasTranslations trait for database-driven model translations.
 *
 * This trait enables Eloquent models to store translatable attributes in separate
 * translation tables using a normalized relational structure. Translations are
 * accessed via locale-aware magic accessors with configurable fallback strategies.
 *
 * @example
 * ```php
 * class Product extends Model
 * {
 *     use HasTranslations;
 *
 *     protected array $translatable = ['name', 'description'];
 * }
 *
 * Product::createWithTranslations([
 *     'sku' => 'ABC123',
 *     'name' => ['en' => 'Laptop', 'fr' => 'Ordinateur'],
 * ]);
 *
 * app()->setLocale('fr');
 * echo $product->name; // "Ordinateur"
 * ```
 *
 * @property array $translatable List of translatable attribute names
 * @property string|null $translationModel Optional: custom translation model class
 */
trait HasTranslations
{
    /**
     * Resolve the translation model class name.
     *
     * By convention, looks for `{Model}Translation` in a `Translations` sub-namespace.
     * Example: `App\Models\Product` resolves to `App\Models\Translations\ProductTranslation`.
     *
     * Override by defining a `$translationModel` property on the model.
     *
     * @return string Fully qualified translation model class name
     */
    protected function translationModel(): string
    {
        if (property_exists($this, 'translationModel')) {
            return $this->translationModel;
        }

        $modelClass = static::class;
        $baseNamespace = Str::beforeLast($modelClass, '\\');
        $modelName = class_basename($modelClass);

        return $baseNamespace . '\\Translations\\' . $modelName . 'Translation';
    }

    /**
     * Define the HasMany relationship to the translation model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function translations(): HasMany
    {
        return $this->hasMany($this->translationModel());
    }

    /**
     * Get the list of translatable attribute names.
     *
     * @return array
     * @throws \HibaSabouh\ModelTranslations\Exceptions\MissingTranslatablePropertyException
     */
    protected function getTranslatableAttributes(): array
    {
        if (!property_exists($this, 'translatable')) {
            throw new MissingTranslatablePropertyException(
                static::class . ' must define a $translatable property.'
            );
        }

        return $this->translatable ?? [];
    }

    /**
     * Create a new model instance with translations.
     *
     * Translatable attributes should be passed as arrays keyed by locale:
     * `['name' => ['en' => 'Laptop', 'fr' => 'Ordinateur']]`
     *
     * The operation runs in a database transaction. If any step fails, all changes are rolled back.
     *
     * @param array $attributes Model attributes with translatable fields as locale-keyed arrays
     * @return static
     * @throws \HibaSabouh\ModelTranslations\Exceptions\InvalidTranslationFormatException
     */
    public static function createWithTranslations(array $attributes): static
    {
        return DB::transaction(function () use ($attributes) {
            $translations = static::extractTranslations($attributes);
            $model = static::create($attributes);

            foreach ($translations as $lang => $fields) {
                $model->translations()->create([
                    'lang' => $lang,
                    ...$fields,
                ]);
            }

            return $model;
        });
    }

    /**
     * Update the model and its translations.
     *
     * Existing translations are updated or created via `updateOrCreate`.
     * Translations for locales not included in the update are left untouched.
     *
     * @param array $attributes Model attributes with translatable fields as locale-keyed arrays
     * @return bool
     * @throws \HibaSabouh\ModelTranslations\Exceptions\InvalidTranslationFormatException
     */
    public function updateWithTranslations(array $attributes): bool
    {
        return DB::transaction(function () use ($attributes) {
            $translations = static::extractTranslations($attributes);
            $updated = $this->update($attributes);
            $foreignKey = $this->getForeignKey();

            foreach ($translations as $lang => $fields) {
                $this->translations()->updateOrCreate(
                    [$foreignKey => $this->id, 'lang' => $lang],
                    $fields
                );
            }

            $this->load('translations');

            return $updated;
        });
    }

    /**
     * Find or create a model with translations.
     *
     * If a matching model exists, it is returned as-is without touching translations.
     * If no match is found, a new model is created with the provided translations.
     *
     * @param array $matchAttributes Attributes to match against
     * @param array $values Additional attributes and translations for creation
     * @return \Illuminate\Database\Eloquent\Model
     * @throws \HibaSabouh\ModelTranslations\Exceptions\InvalidTranslationFormatException
     */
    public static function firstOrCreateWithTranslations(array $matchAttributes, array $values = []): Model
    {
        return DB::transaction(function () use ($matchAttributes, $values) {
            $data = array_merge($matchAttributes, $values);
            $translations = static::extractTranslations($data);
            $model = static::where($matchAttributes)->first();

            if ($model) {
                $model->load('translations');
                return $model;
            }

            $model = static::create($data);

            foreach ($translations as $lang => $fields) {
                $model->translations()->create([
                    'lang' => $lang,
                    ...$fields,
                ]);
            }

            $model->load('translations');

            return $model;
        });
    }

    /**
     * Update or create a model with translations.
     *
     * If a matching model exists, both the model and its translations are updated.
     * If no match is found, a new model is created with translations.
     *
     * @param array $matchAttributes Attributes to match against
     * @param array $values Attributes and translations to update or create
     * @return \Illuminate\Database\Eloquent\Model
     * @throws \HibaSabouh\ModelTranslations\Exceptions\InvalidTranslationFormatException
     */
    public static function updateOrCreateWithTranslations(array $matchAttributes, array $values = []): Model
    {
        return DB::transaction(function () use ($matchAttributes, $values) {
            $data = array_merge($matchAttributes, $values);
            $translations = static::extractTranslations($data);
            $model = static::updateOrCreate($matchAttributes, $data);

            foreach ($translations as $lang => $fields) {
                $model->translations()->updateOrCreate(
                    ['lang' => $lang],
                    $fields
                );
            }

            $model->load('translations');

            return $model;
        });
    }

    /**
     * Extract translation data from attributes array.
     *
     * Translatable attributes are removed from the main `$data` array and returned
     * as a locale-indexed structure: `['en' => ['name' => 'Laptop'], 'fr' => [...]]`
     *
     * @param array $data Attributes array, modified by reference to remove translatable fields
     * @return array Translation data indexed by locale
     * @throws \HibaSabouh\ModelTranslations\Exceptions\InvalidTranslationFormatException
     */
    protected static function extractTranslations(array &$data): array
    {
        $translations = [];
        $translatableAttributes = (new static)->getTranslatableAttributes();

        foreach ($translatableAttributes as $attribute) {
            if (!isset($data[$attribute])) {
                continue;
            }

            if (!is_array($data[$attribute])) {
                throw new InvalidTranslationFormatException($attribute);
            }

            foreach ($data[$attribute] as $lang => $value) {
                $translations[$lang][$attribute] = $value;
            }

            unset($data[$attribute]);
        }

        return $translations;
    }

    /**
     * Magic accessor for translatable attributes and `{attribute}_translations`.
     *
     * **Translatable attributes** (e.g., `$product->name`):
     * Returns the value for the current locale, with fallback strategy applied.
     *
     * **`{attribute}_translations` accessor** (e.g., `$product->name_translations`):
     * Returns all translations as an array: `['en' => 'Laptop', 'fr' => 'Ordinateur']`
     *
     * @param string $key Attribute name
     * @return mixed
     */
    public function __get($key)
    {
        // Handle translatable attributes (e.g., $model->name)
        if (in_array($key, $this->translatable ?? [])) {
            $lang = app()->getLocale();

            if (!$this->relationLoaded('translations')) {
                $translations = $this->translations()->get();
                $this->setRelation('translations', $translations);
            } else {
                $translations = $this->translations;
            }

            $translation = $translations->firstWhere('lang', $lang);

            if (!$translation) {
                $fallbackStrategy = config('translatable.fallback');

                if ($fallbackStrategy === 'app') {
                    $fallbackLocale = config('app.fallback_locale');
                    $translation = $translations->firstWhere('lang', $fallbackLocale);
                }

                if (!$translation && $fallbackStrategy === 'first') {
                    $translation = $translations->first();
                }
            }

            return $translation ? $translation->$key : null;
        }

        // Handle {attribute}_translations accessor (e.g., $model->name_translations)
        if (Str::endsWith($key, '_translations')) {
            $baseKey = Str::before($key, '_translations');

            if (in_array($baseKey, $this->translatable ?? [])) {
                if (!$this->relationLoaded('translations')) {
                    $translations = $this->translations()->get();
                    $this->setRelation('translations', $translations);
                } else {
                    $translations = $this->translations;
                }

                return $translations->mapWithKeys(function ($t) use ($baseKey) {
                    return [$t->lang => $t->$baseKey];
                })->toArray();
            }
        }

        return parent::__get($key);
    }

    /**
     * Boot the trait: register the auto-load scope and query scope macros.
     *
     * Adds a `withTranslations` global scope that eager loads translations
     * when `config('translatable.auto_load')` is true.
     *
     * Registers four query builder macros:
     * - `whereTranslation($attribute, $operator, $value, $lang = null)`
     * - `whereAnyTranslation($attribute, $operator, $value)`
     * - `orWhereTranslation($attribute, $operator, $value, $lang = null)`
     * - `orWhereAnyTranslation($attribute, $operator, $value)`
     *
     * @return void
     */
    public static function bootHasTranslations(): void
    {
        if (config('translatable.auto_load') && !static::hasGlobalScope('withTranslations')) {
            static::addGlobalScope('withTranslations', function (Builder $builder) {
                $builder->with('translations');
            });
        }

        /**
         * Filter by translation in a specific locale (defaults to current locale).
         *
         * @param string $attribute Translation attribute name
         * @param mixed $operatorOrValue Operator or value if operator is '='
         * @param mixed|null $value Value when operator is provided
         * @param string|null $lang Locale code (defaults to app()->getLocale())
         */
        Builder::macro('whereTranslation', function ($attribute, $operatorOrValue, $value = null, $lang = null) {
            $lang = $lang ?: app()->getLocale();

            [$operator, $val] = $value === null
                ? ['=', $operatorOrValue]
                : [$operatorOrValue, $value];

            return $this->whereHas('translations', function ($query) use ($attribute, $operator, $val, $lang) {
                $query->where('lang', $lang)->where($attribute, $operator, $val);
            });
        });

        /**
         * Filter by translation in any locale.
         *
         * @param string $attribute Translation attribute name
         * @param mixed $operatorOrValue Operator or value if operator is '='
         * @param mixed|null $value Value when operator is provided
         */
        Builder::macro('whereAnyTranslation', function ($attribute, $operatorOrValue, $value = null) {
            [$operator, $val] = $value === null
                ? ['=', $operatorOrValue]
                : [$operatorOrValue, $value];

            return $this->whereHas('translations', function ($query) use ($attribute, $operator, $val) {
                $query->where($attribute, $operator, $val);
            });
        });

        /**
         * OR variant of whereTranslation.
         */
        Builder::macro('orWhereTranslation', function ($attribute, $operatorOrValue, $value = null, $lang = null) {
            $lang = $lang ?: app()->getLocale();

            [$operator, $val] = $value === null
                ? ['=', $operatorOrValue]
                : [$operatorOrValue, $value];

            return $this->orWhereHas('translations', function ($query) use ($attribute, $operator, $val, $lang) {
                $query->where('lang', $lang)->where($attribute, $operator, $val);
            });
        });

        /**
         * OR variant of whereAnyTranslation.
         */
        Builder::macro('orWhereAnyTranslation', function ($attribute, $operatorOrValue, $value = null) {
            [$operator, $val] = $value === null
                ? ['=', $operatorOrValue]
                : [$operatorOrValue, $value];

            return $this->orWhereHas('translations', function ($query) use ($attribute, $operator, $val) {
                $query->where($attribute, $operator, $val);
            });
        });
    }
}