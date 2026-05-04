<?php

namespace SportBoeker\PersonalizedSort\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \SportBoeker\PersonalizedSort\PersonalizedSort
 */
class PersonalizedSort extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \SportBoeker\PersonalizedSort\PersonalizedSort::class;
    }
}
