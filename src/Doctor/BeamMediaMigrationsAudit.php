<?php

namespace Splicewire\Beam\Media\Doctor;

use Splicewire\Beam\Doctor\Support\StubMigrationsAudit;
use Splicewire\Beam\Media\BeamMediaServiceProvider;

class BeamMediaMigrationsAudit extends StubMigrationsAudit
{
    protected function packageName(): string
    {
        return 'splicewire/laravel-beam-media';
    }

    protected function serviceProviderClass(): string
    {
        return BeamMediaServiceProvider::class;
    }
}
