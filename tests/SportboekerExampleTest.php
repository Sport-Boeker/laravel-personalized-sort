<?php

use Illuminate\Support\Facades\DB;
use SportBoeker\PersonalizedSort\Examples\Sportboeker\LocationScorer;
use SportBoeker\PersonalizedSort\Examples\Sportboeker\SportboekerProfile;
use SportBoeker\PersonalizedSort\PersonalizedSort;
use SportBoeker\PersonalizedSort\Tests\Fixtures\Product;
use SportBoeker\PersonalizedSort\ValueObjects\Context;

function exampleLocation(array $courts, int $amenities = 0, array $attributes = []): Product
{
    $location = new Product($attributes);
    $location->setRelation('courts', collect($courts)->map(fn (array $court) => new Product($court)));
    $location->setRelation('amenities', collect(array_fill(0, $amenities, new Product)));

    return $location;
}

it('reproduces Sportboeker reference scores for its five weighted rules', function (array $court, int $amenities, ?float $distance, array $preferences, float $expected) {
    $location = exampleLocation([$court + ['is_active' => true]], $amenities, ['distance_km' => $distance]);
    $context = signedInContext(preferences: $preferences, attributes: ['coordinates' => ['latitude' => 52.37, 'longitude' => 4.90]]);

    expect((new LocationScorer)->score($location, $context))->toEqualWithDelta($expected, 0.000001);
})->with([
    'sport and price match at 25 km with two amenities' => [
        ['sport_type_id' => 'tennis', 'min_price' => 50, 'max_price' => 50], 2, 25.0,
        ['has_bookings' => true, 'most_booked_sport_type_id' => 'tennis', 'avg_price' => 50], 0.75,
    ],
    'other sport and double the preferred price at 50 km' => [
        ['sport_type_id' => 'padel', 'min_price' => 100, 'max_price' => 100], 0, 50.0,
        ['has_bookings' => true, 'most_booked_sport_type_id' => 'tennis', 'avg_price' => 50], 0.195,
    ],
    'cold start with no booking history or coordinates' => [
        ['sport_type_id' => 'tennis', 'min_price' => 50, 'max_price' => 50], 0, null,
        ['has_bookings' => false, 'most_booked_sport_type_id' => null, 'avg_price' => null], 0.47,
    ],
    'missing prices use the neutral price contribution' => [
        ['sport_type_id' => 'tennis'], 0, 0.0,
        ['has_bookings' => true, 'most_booked_sport_type_id' => 'tennis', 'avg_price' => 50], 0.745,
    ],
]);

it('ignores inactive courts and handles one-sided price bounds', function () {
    $location = exampleLocation([
        ['is_active' => true, 'sport_type_id' => 'tennis', 'min_price' => 40, 'max_price' => 60],
        ['is_active' => true, 'sport_type_id' => 'padel', 'max_price' => 100],
        ['is_active' => false, 'sport_type_id' => 'tennis', 'min_price' => 10000],
    ], 3, ['distance_km' => 10]);
    $context = signedInContext(
        preferences: ['has_bookings' => true, 'most_booked_sport_type_id' => 'tennis', 'avg_price' => 50],
        attributes: ['coordinates' => ['latitude' => 52.37, 'longitude' => 4.90]],
    );

    expect((new LocationScorer)->score($location, $context))->toEqualWithDelta(0.6433333333, 0.000001);
});

it('uses neutral scores for guests and missing or inactive court relations without queries', function () {
    $scorer = new LocationScorer;
    $inactive = exampleLocation([['is_active' => false, 'sport_type_id' => 'tennis']]);
    $active = exampleLocation([['is_active' => true, 'sport_type_id' => 'tennis']]);
    DB::enableQueryLog();

    expect($scorer->score(new Product, signedInContext()))->toBe(0.5)
        ->and($scorer->score($inactive, signedInContext()))->toBe(0.5)
        ->and($scorer->score($active, new Context))->toBe(0.5)
        ->and(DB::getQueryLog())->toBe([]);

    DB::disableQueryLog();
});

it('supports coordinate fallback including zero latitude and longitude', function () {
    $location = exampleLocation(
        [['is_active' => true, 'sport_type_id' => 'tennis', 'min_price' => 50, 'max_price' => 50]],
        2,
        ['address' => (object) ['coordinates' => (object) ['latitude' => 0.0, 'longitude' => 0.0]]],
    );
    $context = signedInContext(
        preferences: ['has_bookings' => true, 'most_booked_sport_type_id' => 'tennis', 'avg_price' => 50],
        attributes: ['coordinates' => ['latitude' => 0.0, 'longitude' => 0.0]],
    );

    expect((new LocationScorer)->score($location, $context))->toEqualWithDelta(0.875, 0.000001);
});

it('adapts booking history once for a batch and reuses the preference cache', function () {
    $calls = 0;
    $profile = SportboekerProfile::register(app(PersonalizedSort::class), Product::class, function ($id) use (&$calls): array {
        expect($id)->toBe('alice');
        $calls++;

        return ['bookings_count' => 3, 'preferred_sport_type_ids' => ['tennis'], 'avg_total_amount' => 50];
    });
    $tennis = exampleLocation([['is_active' => true, 'sport_type_id' => 'tennis', 'min_price' => 50]]);
    $padel = exampleLocation([['is_active' => true, 'sport_type_id' => 'padel', 'min_price' => 50]]);
    DB::enableQueryLog();
    $ranked = $profile->rank([$padel, $tennis], signedInContext());
    $profile->rank([$padel, $tennis], signedInContext());
    $profile->rank([$padel, $tennis]);

    expect($ranked->first()->courts->first()->sport_type_id)->toBe('tennis')
        ->and($calls)->toBe(1)
        ->and(DB::getQueryLog())->toBe([]);

    DB::disableQueryLog();
});
