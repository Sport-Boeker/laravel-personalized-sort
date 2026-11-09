<?php

namespace SportBoeker\PersonalizedSort\Ranking;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class PostFetchRanking
{
    /**
     * @param  Collection<array-key, Model>  $items
     * @return Collection<int, Model>
     */
    public function rank(Collection $items, string $scoreAttribute): Collection
    {
        return $items->sortByDesc(fn (Model $item): mixed => $item->getAttribute($scoreAttribute))->values();
    }
}
