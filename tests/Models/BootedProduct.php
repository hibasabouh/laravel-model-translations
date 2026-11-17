<?php

namespace Tests\Models;

use HibaSabouh\ModelTranslations\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Tests\Models\Translations\ProductTranslation;

class BootedProduct extends Model
{
    use HasTranslations;

    public static bool $bootedHookRan = false;

    protected $table = 'products';
    protected $fillable = ['sku', 'price'];
    protected $translatable = ['name', 'description'];
    protected string $translationModel = ProductTranslation::class;

    protected static function booted(): void
    {
        static::$bootedHookRan = true;
    }

    public function getForeignKey(): string
    {
        return 'product_id';
    }
}
