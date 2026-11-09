<?php

namespace SportBoeker\PersonalizedSort\Preferences;

use Closure;
use InvalidArgumentException;
use SportBoeker\PersonalizedSort\Contracts\PreferenceProvider;
use SportBoeker\PersonalizedSort\ValueObjects\Context;

final class CallablePreferenceProvider implements PreferenceProvider
{
    private readonly Closure $callback;

    public function __construct(callable $callback)
    {
        $this->callback = Closure::fromCallable($callback);
    }

    public function preferences(Context $context): array
    {
        $preferences = ($this->callback)($context);

        if (! is_array($preferences)) {
            throw new InvalidArgumentException('A preference callback must return an array.');
        }

        return $preferences;
    }
}
