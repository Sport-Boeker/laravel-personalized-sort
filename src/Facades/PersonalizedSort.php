<?php

namespace SportBoeker\PersonalizedSort\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \SportBoeker\PersonalizedSort\PersonalizedSort
 *
 * @method static \SportBoeker\PersonalizedSort\Personalization for(class-string<\Illuminate\Database\Eloquent\Model> $modelClass)
 */
class PersonalizedSort extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \SportBoeker\PersonalizedSort\PersonalizedSort::class;
    }
}
