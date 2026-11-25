<?php

namespace Splicewire\Beam\Media\Routing;

use Closure;
use Illuminate\Routing\Router;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Media\Data\MediaData;
use Splicewire\Beam\Media\Ops\DownloadMedia;
use Splicewire\Beam\Media\Ops\IngestMedia;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Routing\ApiRoot;

final class MediaRoutes
{
    public static function mount(): void
    {
        self::atRoot(function (): void {
            Particle::mount('', 'media')->idConstraint('uuid')->only(['show', 'update', 'destroy']);
            Particle::ops('', 'media', [DownloadMedia::class]);
        });
    }

    /** Optional metered ingest retains the package's named rate limiter and operation ability. */
    public static function ingest(): void
    {
        self::atRoot(fn () => app(Router::class)->middleware('throttle:beam-media.ingest')->group(fn () => Particle::ops('', 'media', [IngestMedia::class])));
    }

    /**
     * Operations a host contributes onto `media`, mounted at the package root by name (owner-review-20261112 58,
     * the Q61-1 shape). Media cannot name their classes, so the host registers them and names them here.
     * `$middleware` is the host's own gate for them (the flagship's music-medium entitlement); state `[]` for none.
     *
     * @param  list<string>  $ops
     * @param  list<string>  $middleware
     */
    public static function ops(array $ops, array $middleware): void
    {
        if ($ops === []) {
            return;
        }
        self::atRoot(fn () => app(Router::class)->middleware($middleware)->group(fn () => Particle::ops('', 'media', $ops)));
    }

    /**
     * Relative edges a host declares from a parent media cannot name onto `media` (the flagship's fragments),
     * mounted at the package root (owner-review-20261112 58, Q58-1 (a)): the host keeps the declaration and its
     * coupling; media supplies the address.
     *
     * @param  list<string>  $relatives  `#[ParticleRelative]` class-strings
     */
    public static function relatives(string $parent, array $relatives): void
    {
        if ($relatives === []) {
            return;
        }
        self::atRoot(fn () => Particle::relatives($parent, $relatives));
    }

    private static function atRoot(Closure $routes): void
    {
        if (! app(ParticleResourceRegistry::class)->has('media')) {
            app(AttributedParticleDiscovery::class)->registerClass(MediaData::class);
        }
        ApiRoot::mount(app(Router::class), 'beam.media.api_root', 'media', fn (Router $router) => $router->name('beam.media.')->group($routes));
    }
}
