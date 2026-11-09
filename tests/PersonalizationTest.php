<?php

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use SportBoeker\PersonalizedSort\Contracts\PreferenceProvider;
use SportBoeker\PersonalizedSort\Contracts\Scorer;
use SportBoeker\PersonalizedSort\Facades\PersonalizedSort;
use SportBoeker\PersonalizedSort\Scoring\NullScorer;
use SportBoeker\PersonalizedSort\Tests\Fixtures\OtherProduct;
use SportBoeker\PersonalizedSort\Tests\Fixtures\Product;
use SportBoeker\PersonalizedSort\ValueObjects\Context;

it('scores copies in input order while preserving keys and the source models', function () {
    $profile = PersonalizedSort::for(Product::class)->add(fn (Product $product) => $product->quality);
    $low = new Product(['id' => 1, 'quality' => 0.2]);
    $high = new Product(['id' => 2, 'quality' => 0.9]);
    $items = $profile->score(['low' => $low, 'high' => $high], signedInContext());

    expect($items->keys()->all())->toBe(['low', 'high'])
        ->and($items['low']->recommendation_score)->toBe(0.2)
        ->and($items['high']->recommendation_score)->toBe(0.9)
        ->and($items['low'])->not->toBe($low)
        ->and($low->getAttributes())->not->toHaveKey('recommendation_score')
        ->and($high->getAttributes())->not->toHaveKey('recommendation_score');
});

it('ranks by score descending and preserves input order for ties', function () {
    $items = [
        new Product(['id' => 3, 'quality' => 0.4]),
        new Product(['id' => 2, 'quality' => 0.9]),
        new Product(['id' => 1, 'quality' => 0.9]),
    ];
    $profile = PersonalizedSort::for(Product::class)->add(fn (Product $item) => $item->quality);

    expect($profile->rank($items, signedInContext())->pluck('id')->all())->toBe([2, 1, 3]);
});

it('loads preferences once per nonempty operation and resolves class dependencies through the container', function () {
    $provider = new class implements PreferenceProvider
    {
        public int $calls = 0;

        public function preferences(Context $context): array
        {
            $this->calls++;

            return ['quality' => 0.8];
        }
    };
    $scorer = new class implements Scorer
    {
        public function __construct(public float $bias = 0.1) {}

        public function score(mixed $item, Context $context): float
        {
            return $context->preferences['quality'] + $this->bias;
        }
    };
    app()->bind($scorer::class, fn () => new ($scorer::class)(0.1));
    app()->instance($provider::class, $provider);
    $profile = PersonalizedSort::for(Product::class)->preferences($provider::class)->add($scorer::class);
    $results = $profile->score(array_fill(0, 10, new Product), signedInContext());

    expect($provider->calls)->toBe(1)
        ->and($results->pluck('recommendation_score')->unique()->all())->toBe([0.9]);

    $profile->score([], signedInContext());
    expect($provider->calls)->toBe(1);
});

it('returns neutral guest scores without loading preferences or calling scorers', function () {
    $profile = PersonalizedSort::for(Product::class)
        ->preferences(fn () => throw new RuntimeException('Guest preference query.'))
        ->add(fn () => throw new RuntimeException('Guest scorer.'));

    expect($profile->score([new Product])->first()->recommendation_score)->toBe(0.5);
});

it('does not leak user scores through a shared array cache or a reused paginator', function () {
    $item = new Product(['id' => 1]);
    $paginator = new LengthAwarePaginator(collect([$item]), 25, 1, 2, ['path' => '/products', 'pageName' => 'products']);
    Cache::put('shared-results', $paginator, 300);

    $profile = PersonalizedSort::for(Product::class)
        ->add(fn (Product $item, Context $context) => $context->preferences['score']);
    $alice = $profile->scorePaginator(Cache::get('shared-results'), signedInContext(preferences: ['score' => 0.9]));
    $bob = $profile->scorePaginator(Cache::get('shared-results'), signedInContext('bob', ['score' => 0.1]));
    $guest = $profile->scorePaginator(Cache::get('shared-results'));

    expect($alice->first()->recommendation_score)->toBe(0.9)
        ->and($bob->first()->recommendation_score)->toBe(0.1)
        ->and($guest->first()->recommendation_score)->toBe(0.5)
        ->and($alice->total())->toBe(25)
        ->and($alice->currentPage())->toBe(2)
        ->and($alice->getPageName())->toBe('products')
        ->and($item->getAttributes())->not->toHaveKey('recommendation_score')
        ->and(Cache::get('shared-results')->first()->getAttributes())->not->toHaveKey('recommendation_score');
});

it('supports replacement scorers and independent model profiles', function () {
    $profile = PersonalizedSort::for(Product::class)->add(fn () => 0)->scorer(new NullScorer(0.8))->attribute('affinity');
    PersonalizedSort::for(OtherProduct::class)->add(fn () => 0.2);

    expect(PersonalizedSort::for(Product::class))->toBe($profile)
        ->and($profile->score([new Product], signedInContext())->first()->affinity)->toBe(0.8)
        ->and(PersonalizedSort::for(OtherProduct::class)->score([new OtherProduct], signedInContext())->first()->recommendation_score)->toBe(0.2);
});

it('rejects a mixed model collection before evaluating preferences', function () {
    $profile = PersonalizedSort::for(Product::class)->preferences(fn () => throw new RuntimeException('Unexpected query.'));

    expect(fn () => $profile->score([new Product, new stdClass], signedInContext()))->toThrow(InvalidArgumentException::class);
});

it('rejects non-model profile classes', function () {
    expect(fn () => PersonalizedSort::for(stdClass::class))->toThrow(InvalidArgumentException::class);
});

it('rejects an invalid preference callback result', function () {
    $profile = PersonalizedSort::for(Product::class)->preferences(fn () => 'invalid');

    expect(fn () => $profile->score([new Product], signedInContext()))->toThrow(InvalidArgumentException::class);
});

it('uses published defaults for neutral scores and the score attribute', function () {
    config()->set('personalized-sort.neutral_score', 0.25);
    config()->set('personalized-sort.score_attribute', 'affinity');
    $profile = PersonalizedSort::for(Product::class);

    expect($profile->score([new Product])->first()->affinity)->toBe(0.25)
        ->and($profile->score([new Product], signedInContext())->first()->affinity)->toBe(0.25);
});
