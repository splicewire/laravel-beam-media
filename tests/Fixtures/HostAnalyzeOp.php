<?php

namespace Splicewire\Beam\Media\Tests\Fixtures;

use Illuminate\Http\Request;
use Splicewire\Beam\Media\Models\Media;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Routing\IdConstraint;

/** Stands in for a write-side op a host contributes onto `media` (the flagship's are Tower's music ops). */
#[ParticleOp(
    resource: 'media',
    name: 'host-analyze',
    kind: OperationKind::Task,
    ability: 'media.analyze',
    input: false,
    idConstraint: IdConstraint::Uuid,
)]
final class HostAnalyzeOp
{
    public static function handle(Media $media, Request $request, mixed $actor): Media
    {
        return $media;
    }
}
