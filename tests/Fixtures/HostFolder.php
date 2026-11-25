<?php

namespace Splicewire\Beam\Media\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Splicewire\Beam\Media\Models\Media;

/** A host parent media cannot name (the flagship's is the knowledge package's Fragment). */
final class HostFolder extends Model
{
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'model');
    }
}
