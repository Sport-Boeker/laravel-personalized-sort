<?php

namespace SportBoeker\PersonalizedSort\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SportBoeker\PersonalizedSort\PersonalizedSortServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            PersonalizedSortServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
