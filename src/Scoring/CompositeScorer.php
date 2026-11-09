<?php

namespace SportBoeker\PersonalizedSort\Scoring;

use SportBoeker\PersonalizedSort\Contracts\Scorer;
use SportBoeker\PersonalizedSort\ValueObjects\Context;

final class CompositeScorer implements Scorer
{
    /** @var list<array{scorer: Scorer, weight: float}> */
    private array $scorers = [];

    public function __construct(private readonly float $neutralScore = 0.5)
    {
        Score::validateNeutral($neutralScore);
    }

    public function add(Scorer $scorer, float $weight = 1.0): self
    {
        Score::validateWeight($weight);
        $this->scorers[] = compact('scorer', 'weight');

        return $this;
    }

    public function score(mixed $item, Context $context): float
    {
        $maxWeight = $this->scorers === [] ? 0.0 : max(array_column($this->scorers, 'weight'));

        if ($maxWeight === 0.0) {
            return $this->neutralScore;
        }

        $weightedScore = 0.0;
        $totalWeight = 0.0;

        foreach ($this->scorers as ['scorer' => $scorer, 'weight' => $weight]) {
            if ($weight === 0.0) {
                continue;
            }

            $normalizedWeight = $weight / $maxWeight;
            $weightedScore += Score::clamp($scorer->score($item, $context)) * $normalizedWeight;
            $totalWeight += $normalizedWeight;
        }

        return Score::clamp($weightedScore / $totalWeight);
    }
}
