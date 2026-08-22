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
];
