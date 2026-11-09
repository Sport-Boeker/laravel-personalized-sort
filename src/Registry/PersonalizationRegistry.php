<?php

namespace SportBoeker\PersonalizedSort\Registry;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use SportBoeker\PersonalizedSort\Personalization;

final class PersonalizationRegistry
{
    /** @var array<class-string<Model>, Personalization> */
    private array $profiles = [];

    public function __construct(
        private readonly Container $container,
        private readonly CacheFactory $cacheFactory,
        private readonly Repository $config,
    ) {}

    public function for(string $modelClass): Personalization
    {
        if (! is_a($modelClass, Model::class, true)) {
            throw new InvalidArgumentException('Personalization profiles require an Eloquent model class.');
        }

        return $this->profiles[$modelClass] ??= new Personalization(
            $modelClass,
            $this->container,
            $this->cacheFactory,
            $this->config->get('personalized-sort.neutral_score', 0.5),
            $this->config->get('personalized-sort.score_attribute', 'recommendation_score'),
            $this->config->get('personalized-sort.max_candidates', 1000),
            $this->config->get('personalized-sort.preference_cache_ttl', 300),
        );
    }
}
