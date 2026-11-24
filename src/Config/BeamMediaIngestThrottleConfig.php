<?php

namespace Splicewire\Beam\Media\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;

/**
 * `beam.media /ingest/throttle` (generated with {@see BeamMediaSection}).
 */
class BeamMediaIngestThrottleConfig extends Data
{
    public function __construct(
        #[Title('Per-user limit'), Description('The most media-ingest requests one signed-in user may make per minute.'), ConfigKey]
        public int $per_actor,
        #[Title('Per-tenant limit'), Description('The most media-ingest requests one tenant may make per minute, counted across all of its users.'), ConfigKey]
        public int $per_tenant,
    ) {
    }
}
