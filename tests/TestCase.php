<?php

namespace Splicewire\Beam\Media\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Splicewire\Beam\Media\BeamMediaServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * beam-media boots with its own provider plus its one declared dependency DOWN,
     * spatie/laravel-medialibrary — whose machinery the primary-image traits drive
     * (addMediaCollection / addMediaConversion) and whose config the media-model binding
     * reads. NO base beam provider: beam-media does not require base beam (no reverse edge).
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BeamMediaServiceProvider::class,
            MediaLibraryServiceProvider::class,
        ];
    }
}
