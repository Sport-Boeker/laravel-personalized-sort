<?php

namespace SportBoeker\PersonalizedSort\Scoring;

use InvalidArgumentException;

final class Score
{
    public static function clamp(float $score): float
    {
        if (! is_finite($score)) {
            throw new InvalidArgumentException('Scores must be finite numbers.');
        }

        return max(0.0, min(1.0, $score));
    }

    public static function validateNeutral(float $score): float
    {
        if (! is_finite($score) || $score < 0 || $score > 1) {
            throw new InvalidArgumentException('The neutral score must be between 0 and 1.');
        }

        return $score;
    }

    public static function validateWeight(float $weight): void
    {
        if (! is_finite($weight) || $weight < 0) {
            throw new InvalidArgumentException('Scorer weights must be finite, non-negative numbers.');
        }
    }
}
