<?php

namespace Splicewire\Beam\Media\Tests;

use Illuminate\Http\Request;
use Splicewire\Beam\Media\Contracts\MediaIngestor;
use Splicewire\Beam\Media\Models\Media;

/** Counts pipeline runs, so "refused" is observed as "the ingestor never ran", not read off a status. */
class SpyMediaIngestor implements MediaIngestor
{
    public int $calls = 0;

    public function ingest(Media $media, Request $request): Media
    {
        $this->calls++;

        return $media;
    }
}
