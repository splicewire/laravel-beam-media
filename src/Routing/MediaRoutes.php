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

    private static function atRoot(Closure $routes): void
    {
        if (! app(ParticleResourceRegistry::class)->has('media')) {
            app(AttributedParticleDiscovery::class)->registerClass(MediaData::class);
        }
        ApiRoot::mount(app(Router::class), 'beam.media.api_root', 'media', fn (Router $router) => $router->name('beam.media.')->group($routes));
    }
}
