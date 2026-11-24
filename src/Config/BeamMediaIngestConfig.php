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
        #[Title('Ingest rate limits'), Description('How often media may be ingested, so processing cost stays bounded.'), ConfigKey]
        public BeamMediaIngestThrottleConfig $throttle,
    ) {
    }
}
