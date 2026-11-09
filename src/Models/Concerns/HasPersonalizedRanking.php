<?php

namespace SportBoeker\PersonalizedSort\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SportBoeker\PersonalizedSort\PersonalizedSort;
use SportBoeker\PersonalizedSort\Ranking\PersonalizedQuery;
use SportBoeker\PersonalizedSort\ValueObjects\Context;

trait HasPersonalizedRanking
{
    /** @param Builder<Model> $query */
    public function scopePersonalized(Builder $query, ?Context $context = null, bool $rank = true): PersonalizedQuery
    {
        return app(PersonalizedSort::class)->for($query->getModel()::class)->query($query, $context, $rank);
    }
}
