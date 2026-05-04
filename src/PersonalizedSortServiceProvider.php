<?php

namespace SportBoeker\PersonalizedSort;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use SportBoeker\PersonalizedSort\Commands\PersonalizedSortCommand;

class PersonalizedSortServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-personalized-sort')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_personalized_sort_table')
            ->hasCommand(PersonalizedSortCommand::class);
    }
}
