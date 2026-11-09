# Sportboeker integration example

This example adapts the rules in Sportboeker's `Modules/Search/app/Scoring/WeightedScorer.php`. The package stays independent of Sportboeker models, repositories, PostGIS, and booking storage.

Copy [LocationScorer.php](LocationScorer.php) and [SportboekerProfile.php](SportboekerProfile.php) into `Modules/Search/app/Personalization/` in the application. Change both namespaces to `Modules\Search\Personalization`. These example classes are only autoloaded by this repository's development configuration; copying them makes them application-owned code.

## Register the profile

Add this registration to the existing Search service provider's `boot()` method, retaining its other registrations:

```php
use Modules\Auth\Repository\UserRepositoryInterface;
use Modules\Club\Models\ClubLocation;
use Modules\Search\Personalization\SportboekerProfile;
use SportBoeker\PersonalizedSort\PersonalizedSort;

SportboekerProfile::register(
    app(PersonalizedSort::class),
    ClubLocation::class,
    static fn ($userId): array => app(UserRepositoryInterface::class)->getUserBookingStats($userId),
);
```

The callback invokes the existing repository once per preference cache miss. It retains the five-minute TTL and derives the preferred sport and average price from booking history. Supply booking amounts and court prices in the same units; this example does not convert currencies or price units.

## Preserve current search behavior

Sportboeker currently orders and paginates in SQL, then attaches scores after retrieving its shared base-result cache. Keep that boundary. In `ApplyRecommendationScores::handle()`, assign the returned paginator:

```php
use Modules\Club\Models\ClubLocation;
use SportBoeker\PersonalizedSort\Facades\PersonalizedSort;
use SportBoeker\PersonalizedSort\ValueObjects\Context;

if ($context->results !== null) {
    $context->results = PersonalizedSort::for(ClubLocation::class)->scorePaginator(
        $context->results,
        new Context(
            user: $context->user,
            attributes: ['coordinates' => $context->userCoordinates],
        ),
    );
}
```

Continue invoking that stage outside the cached pipeline on every request. The returned paginator and its models are copies, so cached source results keep their original attributes. Preserve the stage's call to `$next($context)`.

This mode retains distance, name, or creation-date ordering and the existing paginator total. It does not reorder the current page by recommendation score.

## Add a recommendation sort

For an explicitly selected recommendation sort, build the application's authorized and filtered location query first, eager-load `courts` and `amenities`, then rank before pagination:

```php
use Modules\Club\Models\ClubLocation;
use SportBoeker\PersonalizedSort\Facades\PersonalizedSort;
use SportBoeker\PersonalizedSort\ValueObjects\Context;

$results = PersonalizedSort::for(ClubLocation::class)
    ->query(
        $filteredQuery->with(['courts', 'amenities']),
        new Context(
            user: $request->user(),
            attributes: ['coordinates' => $userCoordinates],
        ),
    )
    ->paginate(perPage: 12)
    ->withQueryString();
```

`$filteredQuery` must already apply the application's public visibility, requested filters, and access restrictions. Do not paginate or limit it first. Recommendation ranking checks the candidate limit (1,000 by default) and raises `CandidateLimitExceeded` if the complete candidate set exceeds it. Apply an appropriate geographic or search filter, or explicitly configure a larger limit.

Keep the existing database ordering/pagination path for other sorts. A shared cache can store unscored candidates; personalized ordering and scores must be applied after retrieval or cached under a fully user-specific key.

## Scoring rules

| Component | Contribution | Missing-data behavior |
| --- | ---: | --- |
| Preferred sport | 30% | `0.5` without booking preferences |
| Preferred price | 20% | `0.5` without usable user or court prices |
| Distance | 25% | `0.5` without coordinates or a usable distance |
| Amenities | 15% | `0.5` without the loaded relation |
| Active court count | 10% | Court count divided by five, capped at `1` |

Guests and locations without loaded active courts receive `0.5` overall. Amenities start at `0.5`, add `0.1` per amenity, and cap at `1`. Distance falls linearly from `1` at zero kilometres to `0` at 50 kilometres. The example prefers a precomputed `distance_km` attribute and otherwise uses Haversine distance from `address.coordinates`. Zero latitude and longitude are valid coordinates.

The court-count component is the application's existing heuristic, not live slot availability. Price matching preserves the existing comparison between the user's average booking amount and court price ranges.

The example's reference scenarios, inactive courts, missing data, coordinate fallback, query count, and preference caching are exercised in [SportboekerExampleTest.php](../../tests/SportboekerExampleTest.php). Actual application adoption should also run the Search module's existing scoring, pipeline, and performance tests through Sail.
