<?php

namespace Splicewire\Beam\Media\Tests\Fixtures;

use Splicewire\Beam\Particle\Attributes\ParticleRelative;

/** The host-declared edge, shaped like the flagship's `App\Routing\FragmentMediaRelative`. */
#[ParticleRelative(
    child: 'media',
    of: 'folders',
    model: HostFolder::class,
    via: 'media',
    binding: 'folder',
    only: ['index', 'store'],
    names: 'folders.media',
    idConstraint: 'uuid',
)]
final class HostFolderMediaRelative {}
