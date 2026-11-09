<?php

namespace SportBoeker\PersonalizedSort\Ranking;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use SportBoeker\PersonalizedSort\Exceptions\CandidateLimitExceeded;
use SportBoeker\PersonalizedSort\Personalization;
use SportBoeker\PersonalizedSort\ValueObjects\Context;

final class PersonalizedQuery
{
    /** @var Builder<Model> */
    private readonly Builder $query;

    /** @param Builder<Model> $query */
    public function __construct(
        Builder $query,
        private readonly Personalization $personalization,
        private readonly Context $context,
        private readonly bool $rank,
        private readonly int $candidateLimit,
    ) {
        $this->query = clone $query;
    }

    /** @return Collection<int, Model> */
    public function get(): Collection
    {
        if (! $this->rank) {
            return $this->personalization->score((clone $this->query)->get(), $this->context)->values();
        }

        return $this->personalization->rank($this->candidates(), $this->context);
    }

    /** @return LengthAwarePaginator<int, Model> */
    public function paginate(int $perPage = 15, ?int $page = null, string $pageName = 'page'): LengthAwarePaginator
    {
        $page ??= Paginator::resolveCurrentPage($pageName);

        if ($perPage < 1 || $page < 1 || $pageName === '') {
            throw new InvalidArgumentException('Page and per-page values must be positive and the page name must not be empty.');
        }

        if (! $this->rank) {
            return $this->personalization->scorePaginator(
                (clone $this->query)->paginate($perPage, ['*'], $pageName, $page),
                $this->context,
            );
        }

        $ranked = $this->get();
        $total = $ranked->count();
        $items = $page > max(1, (int) ceil($total / $perPage))
            ? new Collection
            : $ranked->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /** @return Collection<int, Model> */
    private function candidates(): Collection
    {
        $query = clone $this->query;
        $base = $query->toBase();

        if ($base->limit !== null || $base->offset !== null || $base->unionLimit !== null || $base->unionOffset !== null) {
            throw new InvalidArgumentException('Recommendation ranking requires an unpaginated query. Remove limit/offset before personalizing.');
        }

        if ($base->groups !== null || $base->havings !== null || $base->unions !== null) {
            throw new InvalidArgumentException('Recommendation ranking requires a model query without grouping, having clauses, or unions.');
        }

        $query->orderBy($query->getModel()->getQualifiedKeyName());
        $items = $query->limit($this->candidateLimit + 1)->get();

        if ($items->count() > $this->candidateLimit) {
            throw new CandidateLimitExceeded(
                "Recommendation ranking exceeds the {$this->candidateLimit} candidate limit. Narrow the query or explicitly increase maxCandidates().",
            );
        }

        return $items;
    }
}
