<?php

namespace HibaSabouh\ModelTranslations\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

trait HasTranslations
{
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

    public function translations(): HasMany
    {
        return $this->hasMany($this->translationModel());
    }

    protected function getTranslatableAttributes(): array
    {
        if (!property_exists($this, 'translatable')) {
            throw new \Exception(static::class . ' must define a $translatable property.');
        }

        return $this->translatable ?? [];
    }

    protected static function booted()
    {
        if (config('translatable.auto_load')) {
            $model = new static;
            $scopeName = 'withTranslations';

            if (!array_key_exists($scopeName, $model->getGlobalScopes())) {
                static::addGlobalScope($scopeName, function (Builder $builder) {
                    $builder->with('translations');
                });
            }
        }
    }

    public static function createWithTranslations(array $attributes): static
    {
        return DB::transaction(function () use ($attributes) {
            // Extract translation values per language
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

    public function updateWithTranslations(array $attributes): bool
    {
        return DB::transaction(function () use ($attributes) {
            // Extract translation values per language
            $translations = static::extractTranslations($attributes);

            $updated = $this->update($attributes);

            // Dynamically determine the foreign key name, e.g., 'brand_id'
            $foreign_key = $this->getForeignKey();

            foreach ($translations as $lang => $fields) {
                $this->translations()->updateOrCreate(
                    [
                        $foreign_key => $this->id,
                        'lang'       => $lang,
                    ],
                    $fields
                );
            }

            $this->load('translations');

            return $updated;
        });
    }

    public static function firstOrCreateWithTranslations(array $matchAttributes, array $values = []): Model
    {
        return DB::transaction(function () use ($matchAttributes, $values) {
            $data = array_merge($matchAttributes, $values);

            // Extract translation values per language
            $translations = static::extractTranslations($data);

            // First or create base model
            $model = static::where($matchAttributes)->first();

            if ($model) {
                $model->load('translations');
                return $model;
            }

            $model = static::create($data);

            // Create translations only on creation
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

    public static function updateOrCreateWithTranslations(array $matchAttributes, array $values = []): Model
    {
        return DB::transaction(function () use ($matchAttributes, $values) {
            $data = array_merge($matchAttributes, $values);

            // Extract translation values per language
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

    protected static function extractTranslations(array &$data): array
    {
        $translations = [];

        $translatableAttributes = (new static)->getTranslatableAttributes();

        foreach ($translatableAttributes as $attribute) {
            if (!isset($data[$attribute])) continue;

            if (!is_array($data[$attribute])) {
                throw new BadRequestHttpException(__('errors.unexpected'));
            }

            foreach ($data[$attribute] as $lang => $value) {
                $translations[$lang][$attribute] = $value;
            }

            unset($data[$attribute]);
        }

        return $translations;
    }

    public function __get($key)
    {
        // Handle translatable fields like 'title', 'name', etc.
        if (in_array($key, $this->translatable ?? [])) {
    
            $lang = app()->getLocale();
        
            if (!$this->relationLoaded('translations')) {
                $translations = $this->translations()->get();
                $this->setRelation('translations', $translations);
            } else {
                $translations = $this->translations;
            }
        
            // Try current locale first
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

        // Handle special key like 'title_translations', 'name_translations', etc.
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

    public static function bootHasTranslations()
    {
        // Add macro for locale-specific translation
        Builder::macro('whereTranslation', function ($attribute, $operatorOrValue, $value = null, $lang = null) {
            $lang = $lang ?: app()->getLocale();

            if (func_num_args() === 3) {
                $operator = '=';
                $val = $operatorOrValue;
            } else {
                $operator = $operatorOrValue;
                $val = $value;
            }

            return $this->whereHas('translations', function ($query) use ($attribute, $operator, $val, $lang) {
                $query->where('lang', $lang)
                    ->where($attribute, $operator, $val);
            });
        });

        // Add macro for any-locale translation
        Builder::macro('whereAnyTranslation', function ($attribute, $operatorOrValue, $value = null) {
            if (func_num_args() === 2) {
                $operator = '=';
                $val = $operatorOrValue;
            } else {
                $operator = $operatorOrValue;
                $val = $value;
            }

            return $this->whereHas('translations', function ($query) use ($attribute, $operator, $val) {
                $query->where($attribute, $operator, $val);
            });
        });

        Builder::macro('orWhereTranslation', function ($attribute, $operatorOrValue, $value = null, $lang = null) {
            $lang = $lang ?: app()->getLocale();

            if (func_num_args() === 3) {
                $operator = '=';
                $val = $operatorOrValue;
            } else {
                $operator = $operatorOrValue;
                $val = $value;
            }

            return $this->orWhereHas('translations', function ($query) use ($attribute, $operator, $val, $lang) {
                $query->where('lang', $lang)
                    ->where($attribute, $operator, $val);
            });
        });

        Builder::macro('orWhereAnyTranslation', function ($attribute, $operatorOrValue, $value = null) {
            if (func_num_args() === 2) {
                $operator = '=';
                $val = $operatorOrValue;
            } else {
                $operator = $operatorOrValue;
                $val = $value;
            }

            return $this->orWhereHas('translations', function ($query) use ($attribute, $operator, $val) {
                $query->where($attribute, $operator, $val);
            });
        });
    }

}
