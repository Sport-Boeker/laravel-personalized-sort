<?php

use SportBoeker\PersonalizedSort\Scoring\CallableScorer;
use SportBoeker\PersonalizedSort\Scoring\CompositeScorer;
use SportBoeker\PersonalizedSort\Scoring\NullScorer;
use SportBoeker\PersonalizedSort\ValueObjects\Context;

it('normalizes relative weights and passes the item and context to callbacks', function () {
    $context = signedInContext(preferences: ['category' => 'rackets']);
    $scorer = (new CompositeScorer)
        ->add(new CallableScorer(fn (array $item, Context $ctx): float => $item['category'] === $ctx->preferences['category'] ? 1.0 : 0.0), 3)
        ->add(new CallableScorer(fn (array $item): float => $item['quality']), 1);

    expect($scorer->score(['category' => 'rackets', 'quality' => 0.2], $context))->toEqualWithDelta(0.8, 0.000001);
});

it('keeps weighted scores finite even with very large weights', function () {
    $scorer = (new CompositeScorer)
        ->add(new CallableScorer(fn () => 1), PHP_FLOAT_MAX)
        ->add(new CallableScorer(fn () => 0), PHP_FLOAT_MAX);

    expect($scorer->score(null, new Context))->toBe(0.5);
});

it('uses a neutral score for no scorers or zero total weight without calling disabled scorers', function () {
    $disabled = new CallableScorer(fn () => throw new RuntimeException('Disabled scorer ran.'));

    expect((new CompositeScorer(0.4))->score(null, new Context))->toBe(0.4)
        ->and((new CompositeScorer)->add($disabled, 0)->score(null, new Context))->toBe(0.5)
        ->and((new NullScorer)->score(null, new Context))->toBe(0.5);
});

it('clamps finite callback scores', function (float $input, float $expected) {
    expect((new CallableScorer(fn () => $input))->score(null, new Context))->toBe($expected);
})->with([[2.0, 1.0], [-1.0, 0.0], [0.7, 0.7]]);

it('rejects invalid scorer output', function (mixed $input) {
    expect(fn () => (new CallableScorer(fn () => $input))->score(null, new Context))
        ->toThrow(InvalidArgumentException::class);
})->with(['string' => ['0.5'], 'null' => [null], 'boolean' => [true], 'array' => [[]], 'infinity' => [INF], 'nan' => [NAN]]);

it('rejects invalid weights', function (float $weight) {
    expect(fn () => (new CompositeScorer)->add(new NullScorer, $weight))->toThrow(InvalidArgumentException::class);
})->with([-1.0, INF, NAN]);

it('rejects invalid neutral scores', function (float $score) {
    expect(fn () => new NullScorer($score))->toThrow(InvalidArgumentException::class);
})->with([-0.1, 1.1, INF, NAN]);
