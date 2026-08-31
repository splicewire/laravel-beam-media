<?php

use Splicewire\Beam\Media\Models\Media;
use Splicewire\Beam\Media\Models\ProviderMediaJob;

return [
    /*
     * The Media model. beam-media ships its own UUID-native base Media (extends spatie's
     * base Media, `uuid` primary key). A host (Tower) subclasses it and points this key at
     * its own class; the service provider feeds this into spatie's `media-library.media_model`
     * binding so medialibrary mints the host's subclass. A host that owns the media-library
     * config directly (setting `media_model` itself) still wins — beam-media only fills in
     * the binding when the host hasn't.
     */
    'model' => Media::class,

    /*
     * The ProviderMediaJob model — the record of a unit of media work dispatched outside this
     * application. Same subclass seam as `model` above, and load-bearing for the same reason:
     * `handle` is opaque JSON here because beam-media must not name a `rushing/laravel-prism-plus`
     * type (that edge would land on every beam host). A host driving an async vendor subclasses
     * ProviderMediaJob, adds the typed handle rehydration, and points this key at its own class.
     */
    'job_model' => ProviderMediaJob::class,

    'ingest' => [
        /*
         * The `media.ingest` COST control (particle-write-surface ticket 02).
         *
         * The op's `ability:` answers "may you"; it cannot answer "how often". Ingest is unbounded and
         * repeatable — the same media row can be ingested any number of times and each pass runs the
         * host-bound pipeline, which at the flagship is Tower's `TowerMediaIngestor` (embeddings, graph
         * triples, silo filing). So the bound is a rate limit, and it lives here rather than in the
         * declaration.
         *
         * beam-media registers these as the NAMED limiter `beam-media.ingest`
         * (see BeamMediaServiceProvider::bootIngestRateLimiter). A host attaches it at the mount:
         *
         *     Route::middleware('throttle:beam-media.ingest')->group(function (): void {
         *         Particle::ops('media', 'media', [IngestMedia::class]);
         *     });
         *
         * ## Two limits, because "per actor" and "per tenant" bound different risks
         *
         *   - `per_tenant` is the BUDGET bound. The tenant pays for the tokens, so an actor-only limit
         *     multiplies the intended ceiling by the tenant's member count.
         *   - `per_actor` is the FAIRNESS bound. A tenant-only limit lets one member consume the whole
         *     tenant allowance and deny service to their colleagues.
         *
         * Neither subsumes the other, so both are registered and the stricter one wins per request.
         * When no tenant can be resolved (a bare beam-media install; any non-tenanted host) the tenant
         * limit keys on the actor instead, which is the same bound one member deep.
         *
         * ⚠️ `null` on either key means UNLIMITED for that axis, and both null means the limiter is
         * `Limit::none()`. That is deliberately the shape a test environment gets by setting the keys
         * to null: a suite must never be rate-limited into an order-dependent failure by a default it
         * did not choose. The package's own throttle test sets real numbers explicitly.
         */
        'throttle' => [
            'per_actor' => 30,
            'per_tenant' => 120,
        ],
    ],
];
