<?php

namespace SportBoeker\PersonalizedSort\Examples\Sportboeker;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use SportBoeker\PersonalizedSort\Contracts\Scorer;
use SportBoeker\PersonalizedSort\Scoring\CallableScorer;
use SportBoeker\PersonalizedSort\Scoring\CompositeScorer;
use SportBoeker\PersonalizedSort\ValueObjects\Context;

final class LocationScorer implements Scorer
{
    private readonly CompositeScorer $scorer;

    public function __construct()
    {
        $this->scorer = (new CompositeScorer)
            ->add(new CallableScorer($this->sportMatch(...)), 0.30)
            ->add(new CallableScorer($this->priceMatch(...)), 0.20)
            ->add(new CallableScorer($this->proximity(...)), 0.25)
            ->add(new CallableScorer($this->amenities(...)), 0.15)
            ->add(new CallableScorer($this->availability(...)), 0.10);
    }

    public function score(mixed $item, Context $context): float
    {
        if (! $item instanceof Model) {
            throw new InvalidArgumentException('LocationScorer expects an Eloquent location model.');
        }

        if ($context->isGuest() || $this->activeCourts($item)->isEmpty()) {
            return 0.5;
        }

        return $this->scorer->score($item, $context);
    }

    /** @return Collection<array-key, Model> */
    private function activeCourts(Model $location): Collection
    {
        return $location->relationLoaded('courts')
            ? $location->getRelation('courts')->where('is_active', true)
            : new Collection;
    }

    private function sportMatch(Model $location, Context $context): float
    {
        $sport = $context->preferences['most_booked_sport_type_id'] ?? null;

        if (empty($context->preferences['has_bookings']) || ! $sport) {
            return 0.5;
        }

        $courts = $this->activeCourts($location);
        $matching = $courts->filter(fn (Model $court): bool => $court->getAttribute('sport_type_id') === $sport);

        return $matching->count() / $courts->count();
    }

    private function priceMatch(Model $location, Context $context): float
    {
        $average = $context->preferences['avg_price'] ?? null;

        if (empty($context->preferences['has_bookings']) || ! is_numeric($average) || ! $average) {
            return 0.5;
        }

        $prices = [];

        foreach ($this->activeCourts($location) as $court) {
            $min = $court->getAttribute('min_price');
            $max = $court->getAttribute('max_price');
            $min = is_numeric($min) ? (float) $min : null;
            $max = is_numeric($max) ? (float) $max : null;
            $price = match (true) {
                $min !== null && $max !== null => ($min + $max) / 2,
                $min !== null => $min,
                default => $max,
            };

            if ($price !== null && $price != 0.0) {
                $prices[] = $price;
            }
        }

        $courtAverage = $prices === [] ? null : array_sum($prices) / count($prices);

        if (! $courtAverage) {
            return 0.5;
        }

        return max(0.0, 1.0 - abs($courtAverage - $average) / max($courtAverage, $average));
    }

    private function proximity(Model $location, Context $context): float
    {
        $coordinates = $context->attributes['coordinates'] ?? null;

        if ($coordinates === null) {
            return 0.5;
        }

        $distance = $location->getAttributes()['distance_km'] ?? null;

        if (is_numeric($distance)) {
            return max(0.0, 1.0 - (float) $distance / 50);
        }

        $latitude = data_get($location->getAttribute('address'), 'coordinates.latitude');
        $longitude = data_get($location->getAttribute('address'), 'coordinates.longitude');

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return 0.5;
        }

        $latitudeFrom = deg2rad($coordinates['latitude']);
        $latitudeTo = deg2rad((float) $latitude);
        $latitudeDelta = $latitudeTo - $latitudeFrom;
        $longitudeDelta = deg2rad((float) $longitude) - deg2rad($coordinates['longitude']);
        $a = sin($latitudeDelta / 2) ** 2 + cos($latitudeFrom) * cos($latitudeTo) * sin($longitudeDelta / 2) ** 2;
        $a = max(0.0, min(1.0, $a));
        $distanceKm = 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));

        return max(0.0, 1.0 - $distanceKm / 50);
    }

    private function amenities(Model $location): float
    {
        return $location->relationLoaded('amenities')
            ? min(1.0, 0.5 + $location->getRelation('amenities')->count() * 0.1)
            : 0.5;
    }

    private function availability(Model $location): float
    {
        return min(1.0, $this->activeCourts($location)->count() / 5);
    }
}
