<?php

namespace Splicewire\Beam\Media;

use Illuminate\Support\ServiceProvider;

/**
 * The media arm of the beam family (HTTP-03 / ADR-0178). Owns spatie/laravel-medialibrary,
 * the UUID-native base Media model, the two primary-image traits, and the ubiquitous media
 * table migrations (central + tenant) — all extracted DOWN from beam-core. beam-media does
 * NOT require base beam and base beam does NOT require beam-media (no cycle); a host that
 * wants media requires beam-media directly.
 *
 * Config seam: `config('beam-media.model')` is the media-model binding. This provider feeds
 * it into spatie's `media-library.media_model` so medialibrary mints the configured class —
 * unless the host has already bound its own `media_model` (the host wins). Tower overrides
 * `config('beam-media.model')` with its Media subclass.
 */
class BeamMediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/beam-media.php', 'beam-media');
    }

    public function boot(): void
    {
        $this->bootMediaModelBinding();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/beam-media.php' => $this->app->configPath('beam-media.php'),
            ], 'beam-media-config');

            // PUBLISH-ONLY copies of the ubiquitous media table (moved down from beam-core,
            // which shipped them publish-only too — never loadMigrationsFrom'd at runtime).
            // The flat one lands in the host's database/migrations/ (central pass) and its
            // tenant/ twin in database/migrations/tenant/ (Stancl tenant pass). A host that
            // owns its own runtime media migration (Tower does) never publishes these; they
            // exist for a bare beam-media install that wants the table with no host copy.
            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'beam-media-migrations');
        }
    }

    /**
     * Feed the configured Media model into spatie's media-library binding. Only fills the
     * binding when the host hasn't set its own `media_model` — a host that owns the
     * media-library config directly always wins.
     */
    protected function bootMediaModelBinding(): void
    {
        $model = config('beam-media.model');

        if ($model && ! config('media-library.media_model')) {
            config(['media-library.media_model' => $model]);
        }
    }
}
