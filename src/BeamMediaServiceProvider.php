<?php

namespace Splicewire\Beam\Media;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieBaseMedia;
use Splicewire\Beam\Install\BeamInstallManifest;

/**
 * The media arm of the beam family (HTTP-03 / ADR-0178). Owns spatie/laravel-medialibrary,
 * the UUID-native base Media model, the two primary-image traits, and the ubiquitous media
 * table migration — all extracted DOWN from beam-core. beam-media requires base beam DOWN
 * (HTTP-10, as a particle consumer) — a one-way edge; base beam does not require beam-media
 * back, so there is no cycle.
 *
 * Migration ships as a PUBLISH-ONLY spatie/laravel-package-tools stub (the estate-wide
 * beam-family convention) — see {@see self::configurePackage()}. `media` is genuinely
 * ubiquitous (a tenant-scoped Post's featured image lives in the tenant schema; a central
 * model's media lives in the central schema), so it lives under `shared/` — picked up in
 * BOTH the central and every tenant migration pass by beam-tenancy's
 * `registerSharedMigrationsPath()` — not as a hand-duplicated flat+tenant file pair. The two
 * copies used to drift independently by construction; one file in one directory can't.
 *
 * Config seam: `config('beam.media.model')` is the media-model binding. This provider feeds
 * it into spatie's `media-library.media_model` so medialibrary mints the configured class —
 * unless the host has already bound its own `media_model` (the host wins). Tower overrides
 * `config('beam.media.model')` with its Media subclass.
 */
class BeamMediaServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-beam-media')
            ->hasConfigFile('beam/media')
            // Publish-only .stub migrations (NOT ->discoversMigrations(), which loads at runtime).
            ->hasMigrations([
                'shared/create_media_table',
                'shared/create_provider_media_jobs_table',
            ]);
    }

    public function packageBooted(): void
    {
        // Deliberately BOOT-phase, not packageRegistered(): a host (Tower) overrides
        // config('beam.media.model') from its OWN packageRegistered(), and every provider's
        // register phase completes before any provider's boot phase runs — reading the config
        // here (not at register-time) guarantees the host's override has already landed.
        $this->bootMediaModelBinding();

        // Self-register into beam-core's install manifest so `splicewire:beam:install` publishes
        // this package's migration (shared/, one tag) with the rest of the stack.
        if ($this->app->bound(BeamInstallManifest::class)) {
            $this->app->make(BeamInstallManifest::class)->register(
                package: 'splicewire/laravel-beam-media',
                publishTags: ['beam-media-migrations'],
                migrates: true,
            );
        }
    }

    /**
     * Feed the configured Media model into spatie's media-library binding. Only fills the
     * binding when the host hasn't set its own `media_model` — a host that owns the
     * media-library config directly always wins.
     *
     * Checked against spatie's OWN base class, not falsiness: spatie's own config file already
     * ships `'media_model' => Media::class` (its base class) as a non-null DEFAULT, merged by
     * `MediaLibraryServiceProvider::hasConfigFile('media-library')` well before this runs — so
     * `! config('media-library.media_model')` is always false and this binding never applied,
     * regardless of provider order. Still spatie's own base class means genuinely untouched by
     * any host; anything else (a host's own config/media-library.php publish) wins as before.
     */
    protected function bootMediaModelBinding(): void
    {
        $model = config('beam.media.model');

        if ($model && config('media-library.media_model') === SpatieBaseMedia::class) {
            config(['media-library.media_model' => $model]);
        }
    }
}
