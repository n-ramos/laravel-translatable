<?php

namespace Nramos\Translatable;

use Nramos\Translatable\Commands\TranslatableCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class TranslatableServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('translatable')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_model_translations_table')
            ->hasCommand(TranslatableCommand::class);
    }
}
