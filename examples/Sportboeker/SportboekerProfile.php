<?php

namespace SportBoeker\PersonalizedSort\Examples\Sportboeker;

use Illuminate\Database\Eloquent\Model;
use SportBoeker\PersonalizedSort\Personalization;
use SportBoeker\PersonalizedSort\PersonalizedSort;
use SportBoeker\PersonalizedSort\ValueObjects\Context;

final class SportboekerProfile
{
    /**
     * @param  class-string<Model>  $locationModel
     * @param  callable(mixed): array{bookings_count: int, preferred_sport_type_ids: list<string>, avg_total_amount: float|null}  $bookingStats
     */
    public static function register(PersonalizedSort $sort, string $locationModel, callable $bookingStats): Personalization
    {
        return $sort->for($locationModel)
            ->scorer(LocationScorer::class)
            ->preferences(function (Context $context) use ($bookingStats): array {
                $stats = $bookingStats($context->user?->getAuthIdentifier());

                return [
                    'has_bookings' => $stats['bookings_count'] > 0,
                    'most_booked_sport_type_id' => $stats['preferred_sport_type_ids'][0] ?? null,
                    'avg_price' => $stats['avg_total_amount'],
                ];
            })
            ->cache(ttl: 300);
    }
}
