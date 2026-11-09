<?php

use Illuminate\Auth\GenericUser;
use SportBoeker\PersonalizedSort\Facades\PersonalizedSort;
use SportBoeker\PersonalizedSort\Tests\Fixtures\OtherProduct;
use SportBoeker\PersonalizedSort\Tests\Fixtures\Product;
use SportBoeker\PersonalizedSort\ValueObjects\Context;

it('caches preferences per user and expires or invalidates them', function () {
    $calls = 0;
    $profile = PersonalizedSort::for(Product::class)
        ->preferences(function () use (&$calls): array {
            return ['score' => ++$calls / 10];
        })
        ->add(fn (Product $item, Context $context) => $context->preferences['score'])
        ->cache(ttl: 300);
    $score = fn (Context $context): float => $profile->score([new Product], $context)->first()->recommendation_score;
    $alice = signedInContext();

    expect($score($alice))->toBe(0.1)
        ->and($score($alice))->toBe(0.1)
        ->and($score(signedInContext('bob')))->toBe(0.2)
        ->and($calls)->toBe(2);

    $this->travel(301)->seconds(function () use ($score, $alice): void {
        expect($score($alice))->toBe(0.3);
    });

    $profile->forgetPreferences($alice);
    expect($score($alice))->toBe(0.4);
});

it('separates preference caches by model, user class, context, and version', function () {
    $calls = 0;
    $provider = function () use (&$calls): array {
        $calls++;

        return [];
    };
    $profile = PersonalizedSort::for(Product::class)->preferences($provider)->cache();
    $other = PersonalizedSort::for(OtherProduct::class)->preferences($provider)->cache();

    $profile->score([new Product], signedInContext());
    $profile->score([new Product], signedInContext());
    $profile->score([new Product], signedInContext(attributes: ['tenant' => 'other']));
    $profile->score([new Product], signedInContext(preferences: ['scope' => 'other']));
    $other->score([new OtherProduct], signedInContext());
    $differentUser = new class(['id' => 'alice']) extends GenericUser {};
    $profile->score([new Product], new Context($differentUser));
    $profile->cache(version: 'v2')->score([new Product], signedInContext());

    expect($calls)->toBe(6);
});

it('does not cache guests or users without a persistent identifier', function () {
    $calls = 0;
    $profile = PersonalizedSort::for(Product::class)->preferences(function () use (&$calls): array {
        $calls++;

        return [];
    })->cache();
    $unsaved = new Context(new GenericUser(['id' => null]));

    $profile->score([new Product]);
    $profile->score([new Product], $unsaved);
    $profile->score([new Product], $unsaved);

    expect($calls)->toBe(2);
});

it('can disable preference caching with a zero ttl', function () {
    $calls = 0;
    $profile = PersonalizedSort::for(Product::class)->preferences(function () use (&$calls): array {
        $calls++;

        return [];
    })->cache(ttl: 0);

    $profile->score([new Product], signedInContext());
    $profile->score([new Product], signedInContext());

    expect($calls)->toBe(2);
});
