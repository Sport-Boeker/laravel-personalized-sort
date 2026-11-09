<?php

namespace SportBoeker\PersonalizedSort\Preferences;

use Illuminate\Contracts\Cache\Repository;
use InvalidArgumentException;
use SportBoeker\PersonalizedSort\Contracts\PreferenceProvider;
use SportBoeker\PersonalizedSort\ValueObjects\Context;

final readonly class CachingPreferenceProvider implements PreferenceProvider
{
    public function __construct(
        private PreferenceProvider $provider,
        private Repository $cache,
        private string $namespace,
        private int $ttl = 300,
    ) {
        if ($ttl < 0) {
            throw new InvalidArgumentException('The preference cache TTL must be non-negative.');
        }
    }

    public function preferences(Context $context): array
    {
        $key = $this->key($context);

        if ($key === null || $this->ttl === 0) {
            return $this->provider->preferences($context);
        }

        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $preferences = $this->provider->preferences($context);
        $this->cache->put($key, $preferences, $this->ttl);

        return $preferences;
    }

    public function forget(Context $context): void
    {
        if (($key = $this->key($context)) !== null) {
            $this->cache->forget($key);
        }
    }

    private function key(Context $context): ?string
    {
        $user = $context->user;
        $identifier = $user?->getAuthIdentifier();

        if ($user === null || $identifier === null) {
            return null;
        }

        return 'personalized-sort:preferences:'.hash('sha256', json_encode([
            $this->namespace,
            $user::class,
            $identifier,
            $context->preferences,
            $context->attributes,
        ], JSON_THROW_ON_ERROR));
    }
}
