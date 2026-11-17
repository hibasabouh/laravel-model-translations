# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2026-11-17

### Added
- `orderByTranslation($attribute, $direction = 'asc', $lang = null)` scope to sort by a translated attribute, following the configured fallback strategy
- `orderByTranslationDesc($attribute, $lang = null)` scope
- Public `isTranslatableAttribute($key)` helper

## [1.2.0] - 2026-11-17

### Added
- Support for Laravel 13 and Symfony 8
- GitHub Actions test matrix covering Laravel 10–13 on PHP 8.1–8.5

### Fixed
- `translations:make-model` now adds the `HasTranslations` trait, `$fillable` and `$translatable` on Laravel 11+ (whose model stub has no `use` statement in the class body)
- The `withTranslations` auto-load scope is now registered in `bootHasTranslations()`, so it is no longer lost when a model defines its own `booted()` method

## [1.1.0] - 2026-03-23

### Added
- `translations:make-model` artisan command to scaffold main model, translation model, and optionally migration and resource controller

## [1.0.0] - 2026-02-15

### Added
- `HasTranslations` trait for database-driven model translations
- Auto-resolve translation model by convention (`{Model}Translation` in `Translations` sub-namespace)
- Override translation model via `$translationModel` property
- `translations()` HasMany relationship
- `auto_load` global scope to automatically eager load translations
- Locale-aware magic accessor via `__get()` with configurable fallback strategy (`null`, `app`, `first`)
- `{attribute}_translations` accessor to retrieve all translations keyed by locale
- `createWithTranslations()` static method
- `updateWithTranslations()` instance method
- `firstOrCreateWithTranslations()` static method
- `updateOrCreateWithTranslations()` static method
- `whereTranslation()` query scope (locale-specific, supports operators)
- `whereAnyTranslation()` query scope (all locales, supports operators)
- `orWhereTranslation()` query scope
- `orWhereAnyTranslation()` query scope
- Publishable config file (`config/translatable.php`)
- Support for Laravel 10, 11, and 12
