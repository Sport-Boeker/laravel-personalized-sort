<?php

namespace SportBoeker\PersonalizedSort\Scoring;

use SportBoeker\PersonalizedSort\Contracts\Scorer;
use SportBoeker\PersonalizedSort\ValueObjects\Context;

final readonly class NullScorer implements Scorer
{
    public function __construct(private float $neutralScore = 0.5)
    {
        Score::validateNeutral($neutralScore);
    }

    public function score(mixed $item, Context $context): float
    {
        return $this->neutralScore;
    }
}
