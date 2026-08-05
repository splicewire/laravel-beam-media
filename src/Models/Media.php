<?php

namespace Splicewire\Beam\Media\Models;

use Spatie\MediaLibrary\MediaCollections\Models\Concerns\HasUuid;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;

/**
 * The beam-family base Media model — the default `model` for the media particle and the
 * owner of the media table migration. It extends spatie/laravel-medialibrary's base Media
 * and makes the model UUID-native: `uuid` is the primary key (matching the `uuidMorphs`
 * media table this package ships), so a media record is referenced by a bare uuid across
 * the estate (the `melody_ref` cell-reference contract, blast-radius #4).
 *
 * This is a subclass point: a host (Tower) extends this and binds its subclass via
 * `config('beam-media.model')`. The base is deliberately thin — host-specific concerns
 * (source-meta scopes, flags/tags, a content-url accessor) live on the host subclass, not
 * here. beam-media names no host; the dependency runs one way (host → beam-media).
 */
class Media extends BaseMedia
{
    use HasUuid;

    protected $primaryKey = 'uuid';
}
