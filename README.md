# This is my package laravel-personalized-sort

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sportboeker/laravel-personalized-sort.svg?style=flat-square)](https://packagist.org/packages/sportboeker/laravel-personalized-sort)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/sportboeker/laravel-personalized-sort/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sportboeker/laravel-personalized-sort/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/sportboeker/laravel-personalized-sort/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/sportboeker/laravel-personalized-sort/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/sportboeker/laravel-personalized-sort.svg?style=flat-square)](https://packagist.org/packages/sportboeker/laravel-personalized-sort)

This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.

## Support us

[<img src="https://github-ads.s3.eu-central-1.amazonaws.com/laravel-personalized-sort.jpg?t=1" width="419px" />](https://spatie.be/github-ad-click/laravel-personalized-sort)

We invest a lot of resources into creating [best in class open source packages](https://spatie.be/open-source). You can support us by [buying one of our paid products](https://spatie.be/open-source/support-us).

We highly appreciate you sending us a postcard from your hometown, mentioning which of our package(s) you are using. You'll find our address on [our contact page](https://spatie.be/about-us). We publish all received postcards on [our virtual postcard wall](https://spatie.be/open-source/postcards).

## Installation

You can install the package via composer:

```bash
composer require sportboeker/laravel-personalized-sort
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="laravel-personalized-sort-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-personalized-sort-config"
```

This is the contents of the published config file:

```php
return [
];
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="laravel-personalized-sort-views"
```

## Usage

```php
// app/Providers/PersonalizationServiceProvider.php

use SportBoeker\PersonalizedSort\Facades\PersonalizedSort;
use SportBoeker\PersonalizedSort\Signals\BehavioralSignal;
use SportBoeker\PersonalizedSort\Signals\SessionQuerySignal;
use SportBoeker\PersonalizedSort\Signals\NegativeSignal;
use SportBoeker\PersonalizedSort\Decay\ExponentialDecay;
use SportBoeker\PersonalizedSort\Sources\StoredInteractionSource;
use SportBoeker\PersonalizedSort\Sources\SessionContextSource;
use SportBoeker\PersonalizedSort\Scoring\VectorAffinityScorer;
use SportBoeker\PersonalizedSort\Scoring\CompositeScorerBuilder;
use SportBoeker\PersonalizedSort\Ranking\PgVectorSqlRanking;
use App\Models\Product;
use App\Personalization\ProductVectorizer;
use App\Personalization\ExistingProductScorer;

public function boot(): void
{
    // Weights are relative; the builder normalizes them when composing
    // the user vector and the final score.
    PersonalizedSort::for(Product::class)
        ->vectorizer(ProductVectorizer::class)
        ->signals([
            new BehavioralSignal(
                name: 'purchase',
                weight: 3.0,
                decay: new ExponentialDecay(halfLifeDays: 90),
                source: new StoredInteractionSource('purchase'),
            ),
            new BehavioralSignal(
                name: 'click',
                weight: 1.0,
                decay: new ExponentialDecay(halfLifeDays: 30),
                source: new StoredInteractionSource('click', minDwell: 3),
            ),
            new SessionQuerySignal(
                weight: 1.5,
                source: new SessionContextSource(),
            ),
            new NegativeSignal(
                name: 'skip',
                weight: -0.2,
                decay: new ExponentialDecay(halfLifeDays: 14),
                source: new StoredInteractionSource('impression_skipped'),
            ),
        ])
        ->compose(function (CompositeScorerBuilder $composer) {
            $composer
                ->add(ExistingProductScorer::class, weight: 0.6)
                ->add(VectorAffinityScorer::class, weight: 0.4);
        })
        ->ranking(PgVectorSqlRanking::class)
        ->cache(ttl: 600);
}
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Noah Wilderom](https://github.com/Noah-Wilderom)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
