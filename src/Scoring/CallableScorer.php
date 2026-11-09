<?php

namespace SportBoeker\PersonalizedSort\Scoring;

use Closure;
use InvalidArgumentException;
use SportBoeker\PersonalizedSort\Contracts\Scorer;
use SportBoeker\PersonalizedSort\ValueObjects\Context;

final class CallableScorer implements Scorer
{
    private readonly Closure $callback;

    public function __construct(callable $callback)
    {
        $this->callback = Closure::fromCallable($callback);
    }

    public function score(mixed $item, Context $context): float
    {
        $score = ($this->callback)($item, $context);

        if (! is_int($score) && ! is_float($score)) {
            throw new InvalidArgumentException('A scorer callback must return an int or float.');
        }

        return Score::clamp($score);
    }
}
