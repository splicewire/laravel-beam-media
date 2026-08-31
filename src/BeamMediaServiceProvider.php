<?php

namespace Splicewire\Beam\Media;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieBaseMedia;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Media\Authorization\MediaIngestGate;
use Splicewire\Beam\Media\Models\ProviderMediaJob;
use Splicewire\Beam\Media\Ops\IngestMedia;

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

        // The two halves of `media.ingest`'s bound (particle-write-surface ticket 02) — WHO, and HOW
        // OFTEN. They are separate calls because they are separate questions; see each method.
        $this->bootIngestGate();
        $this->bootIngestRateLimiter();

        // The `provider_media_job` morph alias — the wire identifier this package's polymorphic rows
        // store, and the ADR-0118 permission-token prefix. The package that OWNS the model owns its
        // alias; a host should only have to declare aliases for its OWN models. Without it the model
        // writes its FQCN into every `*_type` column that points at it and into its token prefix,
        // which then cannot move without a data migration.
        //
        // Registered while the table is still EMPTY everywhere (surfaced by voice-profile 29's doctor
        // run, the first host install of this package): an alias is free to choose exactly once, and
        // 28 is about to write the first rows.
        //
        // ADDITIVE (`Relation::morphMap`), NEVER `enforceMorphMap`: a beam-composing host has many
        // models on class-string morphs and global strict mode rejects every one of them. Mirrors
        // {@see \Splicewire\Beam\BeamServiceProvider}.
        //
        // Keyed on the BASE class, deliberately, and NOT on `config('beam.media.job_model')`. A morph
        // map is alias => class: one alias cannot cover two classes, so keying it on the configured
        // subclass would leave the base unaliased and produce TWO tokens for one table — the very
        // failure this line exists to prevent, reintroduced by the seam meant to be flexible.
        //
        // A host that subclasses names its OWN alias from its own provider. That is the estate's worked
        // idiom, not an omission: tower's own service provider declares `'media' => Media::class` and
        // `'video_job' => VideoJob::class` for its own subclasses, while this package aliases what this
        // package owns. Named in prose deliberately — beam-media must not import a symbol from a
        // package downstream of it.
        Relation::morphMap([
            'provider_media_job' => ProviderMediaJob::class,
        ]);

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
    /**
     * Define `media.ingest` as a NAMED Gate ability delegating to {@see MediaIngestGate}.
     *
     * This is the fine-grained widening of {@see IngestMedia}'s coarse permission gate: spatie's
     * `Gate::before` admits the permission-holder and never reaches the ability callback, so anyone
     * else falls through to "can you update the model this media is attached to".
     *
     * `Gate::define`, deliberately NOT `Gate::policy(Media::class, …)` — two reasons, one about blast
     * radius and one about the ability name not being a legal method name. Both are stated in
     * {@see MediaIngestGate}'s class docblock, which is where a reader looking at the rule will be.
     *
     * Unconditional and unguarded: `Gate::define()` is last-write-wins by name, and every provider's
     * boot runs before a host's own `AuthServiceProvider`, so a host redefining `media.ingest` wins
     * without needing a `has`-check here. Registering it costs a bare beam-media install nothing —
     * the op is host-mounted, so nothing asks the question unless a host mounted it.
     */
    protected function bootIngestGate(): void
    {
        Gate::define('media.ingest', [MediaIngestGate::class, 'ingest']);
    }

    /**
     * Register the named `beam-media.ingest` rate limiter — the COST control the ability deliberately
     * does not express. The limits and the reasoning for the two axes live in `config/beam/media.php`;
     * this method is only the wiring.
     *
     * ⚠️ The limiter is registered, NOT attached. Attaching is the host's, at the mount:
     * `Route::middleware('throttle:beam-media.ingest')->group(fn () => Particle::ops('media', 'media',
     * [IngestMedia::class]))`. There is no mount-time middleware seam to use instead — measured
     * 2026-08-31: `ParticleMounter::op()` reads exactly `method`, `idConstraint`, `name`, `streams` and
     * `alias` out of `$options` and passes nothing else to the route, and `PendingParticleMount::ops()`
     * only forwards that same array. A `throttle:` on the mount is therefore a route group, not an
     * option.
     *
     * Registering it here rather than leaving hosts to hand-roll a limiter is the load-bearing part:
     * the numbers are a fact about what the ingest pipeline costs, which is beam-media's to state, and
     * a host that forgets the middleware gets no limiter rather than a wrong one.
     */
    protected function bootIngestRateLimiter(): void
    {
        RateLimiter::for('beam-media.ingest', function (Request $request) {
            $actor = $request->user()?->getAuthIdentifier() ?? $request->ip();

            $tenant = $this->currentTenantKey();

            $limits = [];

            if (($perActor = config('beam.media.ingest.throttle.per_actor')) !== null) {
                $limits[] = Limit::perMinute((int) $perActor)->by('beam-media.ingest:actor:'.$tenant.':'.$actor);
            }

            if (($perTenant = config('beam.media.ingest.throttle.per_tenant')) !== null) {
                $limits[] = Limit::perMinute((int) $perTenant)->by('beam-media.ingest:tenant:'.($tenant ?? $actor));
            }

            // Both axes unlimited ⇒ no limiter at all, rather than an empty array (which Laravel's
            // ThrottleRequests reads as "no limits" too, but says so far less clearly to a reader).
            return $limits === [] ? Limit::none() : $limits;
        });
    }

    /**
     * The current tenant's key, or null when there is no tenant.
     *
     * ⚠️ Probed through the CONTAINER by class-string, never by importing the contract and never through
     * stancl's global `tenant()` helper. Two reasons:
     *
     *   - beam-media sits below tenancy in the topology and must not require it — its own
     *     `package-topology.policy.noRequire` forbids the edge — so the contract cannot be a `use`.
     *   - the global helper is worse than it looks from inside a namespaced class: PHP resolves an
     *     unqualified `tenant()` against the CURRENT namespace first, so `function_exists('tenant')`
     *     (which asks about the global name) and the call it guards can disagree. The container has no
     *     such ambiguity, and it is also what stancl's own helper reads.
     *
     * The binding exists only while a tenant is initialized, which is exactly the condition being
     * asked about — so `bound()` is the question, not a proxy for it.
     */
    protected function currentTenantKey(): int|string|null
    {
        $contract = 'Stancl\Tenancy\Contracts\Tenant';

        if (! $this->app->bound($contract)) {
            return null;
        }

        $tenant = $this->app->make($contract);

        return method_exists($tenant, 'getTenantKey') ? $tenant->getTenantKey() : null;
    }

    protected function bootMediaModelBinding(): void
    {
        $model = config('beam.media.model');

        if ($model && config('media-library.media_model') === SpatieBaseMedia::class) {
            config(['media-library.media_model' => $model]);
        }
    }
}
