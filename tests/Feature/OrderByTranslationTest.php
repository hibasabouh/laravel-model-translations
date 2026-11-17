<?php

use Tests\Models\Order;
use Tests\Models\OrderItem;
use Tests\Models\Product;
use Tests\Models\Translations\ProductTranslation;

// ============================================================
// Helpers
// ============================================================

function sortableProduct(string $sku, array $names): Product
{
    return Product::createWithTranslations(['sku' => $sku, 'price' => 10, 'name' => $names]);
}

function seedSortableProducts(): void
{
    sortableProduct('CHERRY', ['en' => 'Cherry', 'fr' => 'Abricot']);
    sortableProduct('APPLE', ['en' => 'Apple', 'fr' => 'Cerise']);
    sortableProduct('BANANA', ['en' => 'Banana']);
}

function skus($query): array
{
    return $query->pluck('sku')->all();
}

beforeEach(function () {
    config()->set('translatable.fallback', 'app');
});

// ============================================================
// Direction and locale
// ============================================================

it('orders by the current locale ascending by default', function () {
    seedSortableProducts();

    expect(skus(Product::orderByTranslation('name')))->toBe(['APPLE', 'BANANA', 'CHERRY']);
});

it('orders by the current locale descending', function () {
    seedSortableProducts();

    expect(skus(Product::orderByTranslation('name', 'DESC')))->toBe(['CHERRY', 'BANANA', 'APPLE']);
});

it('orderByTranslationDesc matches passing desc', function () {
    seedSortableProducts();

    expect(skus(Product::orderByTranslationDesc('name')))
        ->toBe(skus(Product::orderByTranslation('name', 'desc')));
});

it('orders by an explicit locale instead of the current one', function () {
    config()->set('translatable.fallback', null);
    sortableProduct('CHERRY', ['en' => 'Cherry', 'fr' => 'Abricot']);
    sortableProduct('APPLE', ['en' => 'Apple', 'fr' => 'Cerise']);

    expect(skus(Product::orderByTranslation('name', 'asc', 'fr')))->toBe(['CHERRY', 'APPLE']);
});

// ============================================================
// Fallback strategies
// ============================================================

it('falls back to the app fallback locale when strategy is app', function () {
    seedSortableProducts();
    app()->setLocale('fr');

    // BANANA has no French name, so it sorts by its English "Banana".
    expect(skus(Product::orderByTranslation('name')))->toBe(['CHERRY', 'BANANA', 'APPLE']);
});

it('sorts missing translations as null when strategy is null', function () {
    config()->set('translatable.fallback', null);
    seedSortableProducts();
    app()->setLocale('fr');

    // SQLite sorts NULL first in ascending order.
    expect(skus(Product::orderByTranslation('name')))->toBe(['BANANA', 'CHERRY', 'APPLE']);
});

it('falls back to the first translation when strategy is first', function () {
    config()->set('translatable.fallback', 'first');
    sortableProduct('DATE', ['de' => 'Dattel']);
    sortableProduct('APPLE', ['fr' => 'Pomme']);
    app()->setLocale('fr');

    // DATE has neither French nor English, so only "first" gives it a sort key.
    expect(skus(Product::orderByTranslation('name')))->toBe(['DATE', 'APPLE']);
});

// ============================================================
// Query integrity
// ============================================================

it('returns each row once with main columns even with duplicate translations', function () {
    seedSortableProducts();
    $apple = Product::where('sku', 'APPLE')->first();
    ProductTranslation::create(['product_id' => $apple->id, 'lang' => 'en', 'name' => 'Zucchini']);

    $products = Product::orderByTranslation('name')->get();

    expect($products)->toHaveCount(3)
        ->and($products->pluck('sku')->unique())->toHaveCount(3)
        ->and($products->first()->getAttributes())->toHaveKeys(['id', 'sku', 'price']);
});

it('combines with whereTranslation', function () {
    seedSortableProducts();

    expect(skus(Product::whereTranslation('name', 'like', '%an%')->orderByTranslationDesc('name')))
        ->toBe(['BANANA']);
});

it('keeps paginate totals correct', function () {
    seedSortableProducts();

    $page = Product::orderByTranslation('name')->paginate(2);

    expect($page->total())->toBe(3)
        ->and($page->pluck('sku')->all())->toBe(['APPLE', 'BANANA']);
});

it('is available through relation queries', function () {
    seedSortableProducts();
    $order = Order::create(['reference' => 'ORD-SORT']);
    $item = OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => Product::where('sku', 'CHERRY')->value('id'),
        'quantity'   => 1,
    ]);

    expect($item->product()->orderByTranslation('name')->first()->sku)->toBe('CHERRY');
});

// ============================================================
// Validation
// ============================================================

it('throws on an invalid direction', function () {
    Product::orderByTranslation('name', 'sideways');
})->throws(InvalidArgumentException::class, 'Order direction must be "asc" or "desc".');

it('throws on a non-translatable attribute', function () {
    Product::orderByTranslation('sku');
})->throws(InvalidArgumentException::class, 'is not a translatable attribute');
