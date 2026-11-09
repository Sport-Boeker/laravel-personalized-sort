<?php

namespace SportBoeker\PersonalizedSort\Contracts;

use SportBoeker\PersonalizedSort\ValueObjects\Context;

interface Scorer
{
    public function score(mixed $item, Context $context): float;
}
