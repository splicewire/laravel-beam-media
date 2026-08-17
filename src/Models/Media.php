<?php

namespace Splicewire\Beam\Media\Models;

use Spatie\MediaLibrary\MediaCollections\Models\Concerns\HasUuid;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;
use Splicewire\Beam\Beam;

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

    // The `uuid` primary key is a non-incrementing string. Without these, Laravel applies its default
    // int key-cast on set (`$media->uuid = '9b1d…'` would truncate to `9`), breaking the bare-uuid
    // cell-reference contract (blast-radius #4). A host subclass (Tower's Media) also mixes in Laravel's
    // `HasUuids`, which sets the same pair — declaring them on the base keeps the model correct standalone.
    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * `media` → `beam_media`, routed through the single table-prefix seam {@see Beam::table()}
     * (beam-particle-rename convention). A property default cannot call config(), so the prefix is
     * applied here — a host subclass (Tower's Media) inherits it for free.
     */
    public function getTable(): string
    {
        return Beam::table('media');
    }
}
