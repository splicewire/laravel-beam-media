<?php

namespace Splicewire\Beam\Media\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;

/**
 * `beam.media /ingest` (generated with {@see BeamMediaSection}).
 */
class BeamMediaIngestConfig extends Data
{
    public function __construct(
        #[Title('Rate limits for adding media'), Description('How often new media may be added.'), ConfigKey]
        public BeamMediaIngestThrottleConfig $throttle,
    ) {
    }
}
