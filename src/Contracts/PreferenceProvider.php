<?php

namespace SportBoeker\PersonalizedSort\Contracts;

use SportBoeker\PersonalizedSort\ValueObjects\Context;

interface PreferenceProvider
{
    /** @return array<string, mixed> */
    public function preferences(Context $context): array;
}
