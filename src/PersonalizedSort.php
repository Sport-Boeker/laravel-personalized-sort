<?php

namespace SportBoeker\PersonalizedSort;

use Illuminate\Database\Eloquent\Model;
use SportBoeker\PersonalizedSort\Registry\PersonalizationRegistry;

final readonly class PersonalizedSort
{
    public function __construct(private PersonalizationRegistry $registry) {}

    /** @param class-string<Model> $modelClass */
    public function for(string $modelClass): Personalization
    {
        return $this->registry->for($modelClass);
    }
}
