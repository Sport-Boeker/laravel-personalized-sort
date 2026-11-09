<?php

use Illuminate\Support\ServiceProvider;
use SportBoeker\PersonalizedSort\Facades\PersonalizedSort as PersonalizedSortFacade;
use SportBoeker\PersonalizedSort\PersonalizedSort;
use SportBoeker\PersonalizedSort\PersonalizedSortServiceProvider;

it('resolves the package facade through the container', function () {
    expect(PersonalizedSortFacade::getFacadeRoot())->toBeInstanceOf(PersonalizedSort::class);
});

it('keeps model configuration across container resolutions', function () {
    $manager = app(PersonalizedSort::class);

    expect(app(PersonalizedSort::class))->toBe($manager);
});

it('loads the default configuration and makes it publishable', function () {
    expect(config('personalized-sort.neutral_score'))->toBe(0.5)
        ->and(config('personalized-sort.max_candidates'))->toBe(1000);

    $paths = ServiceProvider::pathsToPublish(
        PersonalizedSortServiceProvider::class,
        'personalized-sort-config',
    );

    expect($paths)->toHaveCount(1);

    foreach ($paths as $source => $destination) {
        expect($source)->toBeFile()
            ->and($destination)->toBe(config_path('personalized-sort.php'));
    }
});
