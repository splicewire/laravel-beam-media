<?php

namespace Splicewire\Beam\Media\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Media\BeamMediaServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * beam-media boots with its own provider plus spatie/laravel-medialibrary (its DOWN dependency
     * whose machinery the primary-image traits drive) AND — since HTTP-10 made beam-media a particle
     * consumer (the MediaData `#[ParticleResource]` + the download/ingest `#[ParticleOp]`s) — base
     * beam's `BeamServiceProvider`, which owns the particle route macros, the discovery seam, and the
     * registries the particle tests exercise. The edge is one-way (beam-media → beam); base beam still
     * does not require beam-media (ADR-0178).
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BeamMediaServiceProvider::class,
            MediaLibraryServiceProvider::class,
            LaravelDataServiceProvider::class,
            BeamServiceProvider::class,
        ];
    }
}
