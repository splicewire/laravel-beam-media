<?php

namespace Splicewire\Beam\Media\Tests\Fixtures;

use Illuminate\Http\Request;
use Splicewire\Beam\Media\Models\Media;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Routing\IdConstraint;

/** Stands in for a read-side op a host contributes onto `media`. */
#[ParticleOp(
    resource: 'media',
    name: 'host-separation',
    kind: OperationKind::Read,
    ability: 'media.separate',
    input: false,
    method: HttpMethod::Get,
    idConstraint: IdConstraint::Uuid,
)]
final class HostSeparationOp
{
    public static function handle(Media $media, Request $request, mixed $actor): Media
    {
        return $media;
    }
}
