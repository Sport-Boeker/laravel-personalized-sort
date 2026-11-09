<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SportBoeker\PersonalizedSort\Exceptions\CandidateLimitExceeded;
use SportBoeker\PersonalizedSort\Facades\PersonalizedSort;
use SportBoeker\PersonalizedSort\Tests\Fixtures\OtherProduct;
use SportBoeker\PersonalizedSort\Tests\Fixtures\Product;

beforeEach(function () {
    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->double('quality');
        $table->boolean('in_stock')->default(true);
    });

    PersonalizedSort::for(Product::class)->add(fn (Product $item): float => $item->quality);
});

it('matches the README quick start scores and ranks products with relative weights', function () {
    PersonalizedSort::for(OtherProduct::class)
        ->add(fn (OtherProduct $product): float => (float) $product->quality, weight: 3)
        ->add(fn (OtherProduct $product): float => $product->in_stock ? 1.0 : 0.0, weight: 1);
    OtherProduct::insert([
        ['name' => 'Stocked', 'quality' => 0.8, 'in_stock' => true],
        ['name' => 'Unavailable', 'quality' => 0.9, 'in_stock' => false],
    ]);

    $page = OtherProduct::query()->where('quality', '>=', 0.1)
        ->personalized(signedInContext())->paginate(perPage: 12);

    expect($page->first()->name)->toBe('Stocked')
        ->and($page->first()->recommendation_score)->toEqualWithDelta(0.85, 0.000001)
        ->and($page->last()->recommendation_score)->toEqualWithDelta(0.675, 0.000001);
});

it('ranks the complete filtered set before paginating without mutating the source query', function () {
    foreach ([0.1, 0.2, 0.9, 0.8, 0.7] as $index => $quality) {
        Product::create(['name' => 'Product '.$index, 'quality' => $quality]);
    }
    Product::create(['name' => 'Excluded', 'quality' => 1.0, 'in_stock' => false]);

    $query = Product::where('in_stock', true)->orderBy('name');
    $originalSql = $query->toSql();
    $ranked = PersonalizedSort::for(Product::class)->query($query, signedInContext());
    $first = $ranked->paginate(perPage: 2, page: 1);
    $second = $ranked->paginate(perPage: 2, page: 2);
    $last = $ranked->paginate(perPage: 2, page: 3);

    expect($first->pluck('id')->all())->toBe([3, 4])
        ->and($second->pluck('id')->all())->toBe([5, 2])
        ->and($last->pluck('id')->all())->toBe([1])
        ->and($first->total())->toBe(5)
        ->and($first->lastPage())->toBe(3)
        ->and($query->toSql())->toBe($originalSql)
        ->and($query->getQuery()->limit)->toBeNull();
});

it('exposes Eloquent integration through the model trait with deterministic key ties', function () {
    Product::insert([
        ['id' => 10, 'name' => 'Third', 'quality' => 0.8],
        ['id' => 2, 'name' => 'First', 'quality' => 0.8],
        ['id' => 5, 'name' => 'Second', 'quality' => 0.8],
    ]);

    expect(Product::query()->personalized(signedInContext())->get()->pluck('id')->all())->toBe([2, 5, 10])
        ->and(Product::query()->personalized()->get()->pluck('recommendation_score')->unique()->all())->toBe([0.5]);
});

it('preserves explicit sorting and database pagination when ranking is disabled', function () {
    Product::insert([
        ['name' => 'Zulu', 'quality' => 0.9],
        ['name' => 'Alpha', 'quality' => 0.1],
        ['name' => 'Middle', 'quality' => 0.7],
    ]);
    PersonalizedSort::for(Product::class)->maxCandidates(1);
    $query = Product::orderBy('name')->personalized(signedInContext(), rank: false);

    DB::enableQueryLog();
    $first = $query->paginate(perPage: 1, page: 1, pageName: 'products');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($first->first()->name)->toBe('Alpha')
        ->and($first->first()->recommendation_score)->toBe(0.1)
        ->and($first->total())->toBe(3)
        ->and($first->getPageName())->toBe('products')
        ->and($queries)->toHaveCount(2)
        ->and($query->get())->toHaveCount(3)
        ->and($query->paginate(perPage: 1, page: 2)->first()->name)->toBe('Middle');
});

it('fails explicitly when the candidate limit is exceeded before loading preferences', function () {
    Product::insert([
        ['name' => 'One', 'quality' => 0.1],
        ['name' => 'Two', 'quality' => 1.0],
    ]);
    PersonalizedSort::for(Product::class)->maxCandidates(1)
        ->preferences(fn () => throw new RuntimeException('Preferences should not load.'));

    expect(fn () => Product::query()->personalized(signedInContext())->paginate())
        ->toThrow(CandidateLimitExceeded::class, '1 candidate limit');
});

it('accepts exactly the configured number of candidates', function () {
    Product::create(['name' => 'One', 'quality' => 0.9]);
    PersonalizedSort::for(Product::class)->maxCandidates(1);

    expect(Product::query()->personalized(signedInContext())->paginate()->total())->toBe(1);
});

it('keeps query counts bounded as the number of ranked items increases', function () {
    $profile = PersonalizedSort::for(Product::class)
        ->preferences(function (): array {
            return ['count' => DB::table('products')->count()];
        });

    foreach ([1, 25] as $count) {
        Product::query()->delete();
        Product::insert(array_fill(0, $count, ['name' => 'Example', 'quality' => 0.5]));
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $profile->query(Product::query(), signedInContext())->get();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($result)->toHaveCount($count)->and($queries)->toHaveCount(2);
    }
});

it('honors model scopes before enforcing the candidate limit', function () {
    Product::insert([
        ['name' => 'Hidden', 'quality' => 1.0, 'in_stock' => false],
        ['name' => 'Visible', 'quality' => 0.1, 'in_stock' => true],
    ]);
    $query = Product::query()->withGlobalScope('stock', fn (Builder $builder) => $builder->where('in_stock', true));
    PersonalizedSort::for(Product::class)->maxCandidates(1);

    expect($query->personalized(signedInContext())->get()->pluck('name')->all())->toBe(['Visible']);
});

it('rejects pre-limited or offset candidate queries including global scope limits', function (string $kind) {
    $query = Product::query();

    match ($kind) {
        'limit' => $query->limit(1),
        'offset' => $query->offset(1),
        'scope' => $query->withGlobalScope('limited', fn (Builder $builder) => $builder->limit(1)),
    };

    expect(fn () => $query->personalized(signedInContext())->get())
        ->toThrow(InvalidArgumentException::class, 'unpaginated query');
})->with(['limit', 'offset', 'scope']);

it('rejects aggregate and union queries that do not describe a plain candidate set', function (string $kind) {
    $query = Product::query();

    match ($kind) {
        'group' => $query->groupBy('quality'),
        'having' => $query->having('quality', '>', 0.5),
        'union' => $query->union(Product::query()),
    };

    expect(fn () => $query->personalized(signedInContext())->get())->toThrow(InvalidArgumentException::class);
})->with(['group', 'having', 'union']);

it('returns valid metadata for empty and far-out pages', function () {
    $empty = Product::query()->personalized(signedInContext())->paginate(perPage: 2, page: 1);
    Product::create(['name' => 'One', 'quality' => 0.9]);
    $far = Product::query()->personalized(signedInContext())->paginate(perPage: PHP_INT_MAX, page: PHP_INT_MAX);

    expect($empty->total())->toBe(0)
        ->and($empty->items())->toBe([])
        ->and($far->total())->toBe(1)
        ->and($far->items())->toBe([]);
});

it('rejects nonpositive pagination parameters', function (int $perPage, int $page) {
    expect(fn () => Product::query()->personalized()->paginate($perPage, $page))->toThrow(InvalidArgumentException::class);
})->with([[0, 1], [1, 0], [-1, 1], [1, -1]]);
