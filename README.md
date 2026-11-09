# Laravel Personalized Sort

[![Tests](https://img.shields.io/github/actions/workflow/status/Sport-Boeker/laravel-personalized-sort/run-tests.yml?branch=master&label=tests)](https://github.com/Sport-Boeker/laravel-personalized-sort/actions/workflows/run-tests.yml)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/Sport-Boeker/laravel-personalized-sort/phpstan.yml?branch=master&label=PHPStan)](https://github.com/Sport-Boeker/laravel-personalized-sort/actions/workflows/phpstan.yml)
[![Code style](https://img.shields.io/github/actions/workflow/status/Sport-Boeker/laravel-personalized-sort/fix-php-code-style-issues.yml?branch=master&label=code%20style)](https://github.com/Sport-Boeker/laravel-personalized-sort/actions/workflows/fix-php-code-style-issues.yml)

Add configurable recommendation scores and personalized ordering to Eloquent models. Combine your own scoring rules with relative weights, load user preferences once per operation, and rank results before pagination.

The package handles composition, caching, ordering, and pagination. Your application supplies the business rules and data. No tracking database, external API, vector database, or model training is required.

## Contents

- [Installation](#installation)
- [Quick start](#quick-start)
- [Scoring existing results](#scoring-existing-results)
- [User context and preferences](#user-context-and-preferences)
- [Preference caching](#preference-caching)
- [Custom scorers](#custom-scorers)
- [Recommendation pagination](#recommendation-pagination)
- [Respecting explicit sorts](#respecting-explicit-sorts)
- [Sportboeker example](#sportboeker-example)
- [Configuration](#configuration)
- [Testing and contributing](#testing-and-contributing)

## Installation

Requires **PHP 8.4+** and **Laravel 12 or 13**.

~~~bash
composer require sportboeker/laravel-personalized-sort
~~~

Laravel discovers the service provider and facade automatically. There are no package migrations to run.

Optionally publish the configuration:

~~~bash
php artisan vendor:publish --tag="personalized-sort-config"
~~~

## Quick start

These examples assume an application-owned `Product` model with a numeric `quality` attribute between 0 and 1 and a boolean `in_stock` attribute.

### 1. Enable Eloquent integration

Add the trait to your existing model:

~~~php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use SportBoeker\PersonalizedSort\Models\Concerns\HasPersonalizedRanking;

class Product extends Model
{
    use HasPersonalizedRanking;
}
~~~

The trait is optional: the facade also accepts ordinary Eloquent models.

### 2. Register scoring rules

Configure profiles once in a service provider's `boot()` method:

~~~php
use App\Models\Product;
use SportBoeker\PersonalizedSort\Facades\PersonalizedSort;

public function boot(): void
{
    PersonalizedSort::for(Product::class)
        ->add(fn (Product $product): float => (float) $product->quality, weight: 3)
        ->add(fn (Product $product): float => $product->in_stock ? 1.0 : 0.0, weight: 1);
}
~~~

Weights are relative: these rules contribute 75% quality and 25% stock status. A product with quality `0.8` that is in stock receives `0.85`.

### 3. Fetch recommendations

Pass the current user explicitly from your controller:

~~~php
use App\Models\Product;
use SportBoeker\PersonalizedSort\ValueObjects\Context;

$products = Product::query()
    ->where('quality', '>=', 0.1)
    ->personalized(new Context(user: $request->user()))
    ->paginate(perPage: 12)
    ->withQueryString();
~~~

Results are ordered by `recommendation_score`, highest first. Guests receive the neutral score `0.5`; their preference providers and scorers are not executed.

Apply filters, eager loads, and selections **before** `personalized()`. It returns a ranking object with `get()` and `paginate()`.

Without the trait, use the same API through a profile:

~~~php
$products = PersonalizedSort::for(Product::class)
    ->query(Product::query()->where('in_stock', true), new Context(user: $request->user()))
    ->paginate(perPage: 12);
~~~

## Scoring existing results

`score()` adds the score without changing the input order. `rank()` also sorts descending and resets collection keys:

~~~php
$profile = PersonalizedSort::for(Product::class);
$context = new Context(user: $request->user());
$products = Product::query()->where('in_stock', true)->get();

$scored = $profile->score($products, $context);
$ranked = $profile->rank($products, $context);
~~~

Scoring returns **cloned models**. Input models and shared cached result collections retain their original attributes. Scorers should read their inputs without mutating them or their related models.

`score()` preserves collection keys. For equal scores, `rank()` preserves input order. Scores are transient attributes; use the returned models for display rather than saving them. If a model restricts JSON fields with `$visible`, include the score attribute there or call `makeVisible()` on the returned models.

For a paginator already fetched or retrieved from a shared cache:

~~~php
$scoredPage = $profile->scorePaginator($cachedPaginator, $context);
~~~

The returned paginator preserves its totals, page size, current page, and options. This annotates the existing page; use recommendation pagination below to rank across pages.

## User context and preferences

A context carries the current user, optional preferences, and request-specific attributes. Pass the user explicitly; omitting the context uses guest behavior.

~~~php
$context = new Context(
    user: $request->user(),
    preferences: ['preferred_category' => 'rackets'],
    attributes: ['tenant_id' => $tenant->getKey()],
);

PersonalizedSort::for(Product::class)
    ->add(
        fn (Product $product, Context $context): float =>
            $product->category === ($context->preferences['preferred_category'] ?? null) ? 1.0 : 0.5,
        weight: 2,
    );
~~~

Alternatively, register a preference resolver. In this example the application's user model has a `favorite_category` attribute:

~~~php
PersonalizedSort::for(Product::class)
    ->preferences(fn (Context $context): array => [
        'preferred_category' => $context->user->favorite_category,
    ]);
~~~

The resolver runs once per nonempty scoring or ranking operation, not once per item. Its returned array replaces the context's supplied preferences for that operation. It never runs for guests or empty collections.

Preference classes can implement `Contracts\PreferenceProvider`:

~~~php
public function preferences(Context $context): array;
~~~

Pass a provider instance or its class name to `preferences()`. Class names are resolved through Laravel's container for each operation, so constructor injection works. Callbacks and registered scorer instances should be stateless; obtain request-specific data from `Context`.

Without a configured scorer, or with only zero-weight scorers, authenticated users also receive the configured neutral score. For users without history, define missing-preference fallbacks in your scorers.

## Preference caching

Caching is opt-in and caches **preferences**, not scored results:

~~~php
PersonalizedSort::for(Product::class)
    ->preferences(fn (Context $context): array => [
        'preferred_category' => $context->user->favorite_category,
    ])
    ->cache(ttl: 300, store: 'redis', version: 'v1');
~~~

Omit `store` to use Laravel's default cache store. `cache()` uses the configured default TTL; `cache(ttl: 0)` disables caching.

Keys include the model profile, cache version, user class and identifier, supplied preferences, and context attributes. Include tenant or other preference-affecting context in `attributes`. Attributes and supplied preferences must be JSON-serializable when caching is enabled. Guests and users without an identifier are not cached.

Forget one context's cached preferences after a relevant change:

~~~php
PersonalizedSort::for(Product::class)->forgetPreferences($context);
~~~

Use the same supplied preferences and attributes when invalidating. Increment `version` when changing preference logic. Booking/order changes do not automatically invalidate your application's preferences; choose explicit invalidation or an acceptable TTL.

Shared base-result caches can remain user-independent. Apply `score()` or `scorePaginator()` **after** retrieving shared results on every request.

## Custom scorers

A scorer receives one item and the prepared context:

~~~php
namespace App\Personalization;

use SportBoeker\PersonalizedSort\Contracts\Scorer;
use SportBoeker\PersonalizedSort\ValueObjects\Context;

final class QualityScorer implements Scorer
{
    public function score(mixed $item, Context $context): float
    {
        return (float) $item->quality;
    }
}
~~~

Register an instance, a class name resolved by the container, or a callable:

~~~php
use App\Personalization\QualityScorer;

PersonalizedSort::for(Product::class)
    ->add(QualityScorer::class, weight: 3)
    ->add(fn (Product $product): float => $product->in_stock ? 1.0 : 0.0, weight: 1);
~~~

`add()` appends a rule. `scorer()` replaces the existing rules, which is useful for switching strategies:

~~~php
use SportBoeker\PersonalizedSort\Scoring\NullScorer;

PersonalizedSort::for(Product::class)->scorer(new NullScorer);
~~~

For reusable compositions, `Scoring\CompositeScorer` also accepts `Scorer` instances through `add()`. Wrap callbacks with `Scoring\CallableScorer`.

Individual finite scores are clamped to `[0, 1]` before blending. Weights must be finite and non-negative; a zero-weight rule is skipped. Non-numeric callback results, `NAN`, and infinite scores or weights raise an exception.

Scorers should use eager-loaded relations and precomputed attributes. The package performs no per-item queries itself, but a scorer that lazily loads a relation can still introduce an N+1 problem.

## Recommendation pagination

Recommendation ordering fetches the filtered candidate set, scores and sorts it, then slices the requested page. A high-scoring item can appear on page 1 even if it would otherwise have been fetched on a later SQL page.

Collection ties preserve input order. Query ties retain the SQL candidate order, with the model's primary key appended as a final tie-breaker.

~~~php
$profile = PersonalizedSort::for(Product::class)->maxCandidates(2000);

$page = $profile
    ->query(Product::query()->where('in_stock', true), $context)
    ->paginate(perPage: 12, page: 2, pageName: 'products');
~~~

The default limit is **1,000 candidates**. The query fetches at most the limit plus one. Exceeding the limit throws `Exceptions\CandidateLimitExceeded` before loading preferences; it never silently truncates candidates or invents a paginator total. Narrow the query or explicitly raise the limit to suit your memory budget.

Recommendation queries must select model rows without pre-applied `limit`, `offset`, grouping, `having`, or unions. Filter the candidate set first. Existing model scopes are respected.

This is PHP ranking for bounded candidate sets. SQL scoring, cursor pagination, approximate retrieval, and pgvector ranking are not provided in this release.

## Respecting explicit sorts

Use `rank: false` when the user asks for a different sort. SQL controls ordering and pagination; the package only scores returned rows:

~~~php
$products = Product::query()
    ->orderBy('name')
    ->personalized(new Context(user: $request->user()), rank: false)
    ->paginate(perPage: 12);
~~~

This mode uses normal database pagination and does not apply the recommendation candidate limit. The same option is available on `$profile->query($query, $context, rank: false)`.

Calling `personalized()` with its default `rank: true` explicitly requests recommendation ordering. Select the mode in your application's controller or search pipeline based on the requested sort.

## Sportboeker example

The [Sportboeker adapter](examples/Sportboeker/README.md) extracts the existing application's scoring rules into an application-owned example:

| Rule | Weight |
| --- | ---: |
| Most-booked sport match | 30% |
| Average booking price match | 20% |
| Distance, falling to zero at 50 km | 25% |
| Amenity count | 15% |
| Active court count | 10% |

It uses a five-minute preference cache, returns neutral scores for guests or locations without active courts, and performs no per-location queries. Active court count is the existing availability heuristic; it does not check live booking slots.

The example includes registration, annotation of an existing cached page, and recommendation ordering. Its reference scores and cache behavior are covered by the package tests. The application remains responsible for its models, booking aggregates, public visibility, filters, and geographic query extensions.

## Configuration

Published as `config/personalized-sort.php`:

~~~php
return [
    'neutral_score' => 0.5,
    'score_attribute' => 'recommendation_score',
    'max_candidates' => 1000,
    'preference_cache_ttl' => 300,
];
~~~

Override the attribute or candidate limit for an individual model:

~~~php
PersonalizedSort::for(Product::class)
    ->attribute('affinity_score')
    ->maxCandidates(2000);
~~~

Register model profiles in a service provider. The configuration file contains serializable defaults and works with `php artisan config:cache`.

The earlier scaffold's behavioral signals, interaction recording, time decay, vectorizers, Scout adapters, and pgvector strategy are deferred. They are not shipped as empty APIs.

## Testing and contributing

~~~bash
composer install
composer test
composer analyse
composer format -- --test
~~~

Run `composer format` to fix formatting. For local coverage, enable Xdebug or PCOV and run `composer test-coverage`.

CI tests PHP 8.4 and 8.5 with Laravel 12 and 13, using stable and lowest compatible dependencies on Linux and Windows. Tests cover composition, cache isolation and expiry, model cloning, pagination, explicit sorts, candidate limits, query counts, and the Sportboeker example.

Laravel 11 is outside this release's supported range: its available releases are blocked by Composer's security advisory checks. Dependency security blocking remains enabled.

Bug reports and pull requests are welcome. Include a reproducible example and tests for behavior changes.

## License

MIT. See [LICENSE.md](LICENSE.md). Created by [Noah Wilderom](https://github.com/Noah-Wilderom).
