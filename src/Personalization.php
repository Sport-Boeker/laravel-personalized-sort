<?php

namespace SportBoeker\PersonalizedSort;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use SportBoeker\PersonalizedSort\Contracts\PreferenceProvider;
use SportBoeker\PersonalizedSort\Contracts\Scorer;
use SportBoeker\PersonalizedSort\Preferences\CachingPreferenceProvider;
use SportBoeker\PersonalizedSort\Preferences\CallablePreferenceProvider;
use SportBoeker\PersonalizedSort\Ranking\PersonalizedQuery;
use SportBoeker\PersonalizedSort\Ranking\PostFetchRanking;
use SportBoeker\PersonalizedSort\Scoring\CallableScorer;
use SportBoeker\PersonalizedSort\Scoring\CompositeScorer;
use SportBoeker\PersonalizedSort\Scoring\Score;
use SportBoeker\PersonalizedSort\ValueObjects\Context;

final class Personalization
{
    /** @var list<array{scorer: Scorer|callable|class-string<Scorer>, weight: float}> */
    private array $scorers = [];

    /** @var PreferenceProvider|callable|class-string<PreferenceProvider>|null */
    private mixed $preferenceProvider = null;

    private ?int $cacheTtl = null;

    private ?string $cacheStore = null;

    private string $cacheVersion = 'v1';

    /** @param class-string<Model> $modelClass */
    public function __construct(
        public readonly string $modelClass,
        private readonly Container $container,
        private readonly CacheFactory $cacheFactory,
        private readonly float $neutralScore = 0.5,
        private string $scoreAttribute = 'recommendation_score',
        private int $candidateLimit = 1000,
        private readonly int $defaultCacheTtl = 300,
    ) {
        Score::validateNeutral($neutralScore);
        $this->attribute($scoreAttribute);
        $this->maxCandidates($candidateLimit);
    }

    /** @param Scorer|callable|class-string<Scorer> $scorer */
    public function add(Scorer|callable|string $scorer, float $weight = 1.0): self
    {
        Score::validateWeight($weight);
        $this->scorers[] = compact('scorer', 'weight');

        return $this;
    }

    /** @param Scorer|callable|class-string<Scorer> $scorer */
    public function scorer(Scorer|callable|string $scorer): self
    {
        $this->scorers = [];

        return $this->add($scorer);
    }

    /** @param PreferenceProvider|callable|class-string<PreferenceProvider> $provider */
    public function preferences(PreferenceProvider|callable|string $provider): self
    {
        $this->preferenceProvider = $provider;

        return $this;
    }

    public function cache(?int $ttl = null, ?string $store = null, string $version = 'v1'): self
    {
        $ttl ??= $this->defaultCacheTtl;

        if ($ttl < 0) {
            throw new InvalidArgumentException('The preference cache TTL must be non-negative.');
        }

        $this->cacheTtl = $ttl;
        $this->cacheStore = $store;
        $this->cacheVersion = $version;

        return $this;
    }

    public function attribute(string $name): self
    {
        if ($name === '' || str_contains($name, '.')) {
            throw new InvalidArgumentException('The score attribute must be a non-empty attribute name without dots.');
        }

        $this->scoreAttribute = $name;

        return $this;
    }

    public function maxCandidates(int $limit): self
    {
        if ($limit < 1 || $limit === PHP_INT_MAX) {
            throw new InvalidArgumentException('The candidate limit must be between 1 and PHP_INT_MAX - 1.');
        }

        $this->candidateLimit = $limit;

        return $this;
    }

    /**
     * @param  iterable<array-key, Model>  $items
     * @return Collection<array-key, Model>
     */
    public function score(iterable $items, ?Context $context = null): Collection
    {
        $items = new Collection($items);

        foreach ($items as $item) {
            if (! $item instanceof $this->modelClass) {
                throw new InvalidArgumentException("All items must be instances of {$this->modelClass}.");
            }
        }

        if ($items->isEmpty()) {
            return $items;
        }

        $context ??= new Context;
        $scorer = null;

        if (! $context->isGuest()) {
            if (($provider = $this->resolvePreferences()) !== null) {
                $context = $context->withPreferences($provider->preferences($context));
            }

            $scorer = $this->resolveScorer();
        }

        return $items->map(function (Model $item) use ($context, $scorer): Model {
            $copy = clone $item;
            $copy->setAttribute(
                $this->scoreAttribute,
                $scorer === null ? $this->neutralScore : Score::clamp($scorer->score($copy, $context)),
            );

            return $copy;
        });
    }

    /**
     * @param  iterable<array-key, Model>  $items
     * @return Collection<int, Model>
     */
    public function rank(iterable $items, ?Context $context = null): Collection
    {
        return (new PostFetchRanking)->rank($this->score($items, $context), $this->scoreAttribute);
    }

    /**
     * @param  LengthAwarePaginator<int, Model>  $paginator
     * @return LengthAwarePaginator<int, Model>
     */
    public function scorePaginator(LengthAwarePaginator $paginator, ?Context $context = null): LengthAwarePaginator
    {
        $copy = clone $paginator;
        $copy->setCollection($this->score($paginator->getCollection(), $context)->values());

        return $copy;
    }

    /** @param Builder<Model> $query */
    public function query(Builder $query, ?Context $context = null, bool $rank = true): PersonalizedQuery
    {
        if (! $query->getModel() instanceof $this->modelClass) {
            throw new InvalidArgumentException("The query must select {$this->modelClass} models.");
        }

        return new PersonalizedQuery($query, $this, $context ?? new Context, $rank, $this->candidateLimit);
    }

    public function forgetPreferences(Context $context): void
    {
        $provider = $this->resolvePreferences();

        if ($provider instanceof CachingPreferenceProvider) {
            $provider->forget($context);
        }
    }

    private function resolveScorer(): CompositeScorer
    {
        $composite = new CompositeScorer($this->neutralScore);

        foreach ($this->scorers as ['scorer' => $scorer, 'weight' => $weight]) {
            if ($weight === 0.0) {
                continue;
            }

            if (is_string($scorer) && ! is_callable($scorer)) {
                $scorer = $this->container->make($scorer);
            }

            if (! $scorer instanceof Scorer) {
                if (! is_callable($scorer)) {
                    throw new InvalidArgumentException('Scorers must implement Scorer or be callable.');
                }

                $scorer = new CallableScorer($scorer);
            }

            $composite->add($scorer, $weight);
        }

        return $composite;
    }

    private function resolvePreferences(): ?PreferenceProvider
    {
        $provider = $this->preferenceProvider;

        if ($provider === null) {
            return null;
        }

        if (is_string($provider) && ! is_callable($provider)) {
            $provider = $this->container->make($provider);
        }

        if (! $provider instanceof PreferenceProvider) {
            if (! is_callable($provider)) {
                throw new InvalidArgumentException('Preference providers must implement PreferenceProvider or be callable.');
            }

            $provider = new CallablePreferenceProvider($provider);
        }

        if ($this->cacheTtl !== null) {
            $provider = new CachingPreferenceProvider(
                $provider,
                $this->cacheFactory->store($this->cacheStore),
                $this->modelClass.':'.$this->cacheVersion,
                $this->cacheTtl,
            );
        }

        return $provider;
    }
}
