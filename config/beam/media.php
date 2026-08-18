<?php

use Splicewire\Beam\Media\Models\Media;

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
];
