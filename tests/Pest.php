<?php

use Illuminate\Auth\GenericUser;
use SportBoeker\PersonalizedSort\Tests\TestCase;
use SportBoeker\PersonalizedSort\ValueObjects\Context;

uses(TestCase::class)->in(__DIR__);

function signedInContext(string $id = 'alice', array $preferences = [], array $attributes = []): Context
{
    return new Context(new GenericUser(['id' => $id]), $preferences, $attributes);
}
