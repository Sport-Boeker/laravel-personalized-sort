<?php

namespace SportBoeker\PersonalizedSort;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use SportBoeker\PersonalizedSort\Registry\PersonalizationRegistry;

class PersonalizedSortServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-personalized-sort')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(PersonalizationRegistry::class);
        $this->app->singleton(PersonalizedSort::class);
    }
}
