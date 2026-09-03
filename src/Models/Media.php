<?php

namespace Splicewire\Beam\Media\Models;

use Spatie\MediaLibrary\MediaCollections\Models\Concerns\HasUuid;
use Rushing\PermissionCascade\Attributes\UseCascadePolicy;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;
use Splicewire\Beam\Facades\Beam;

/**
 * The beam-family base Media model — the default `model` for the media particle and the
 * owner of the media table migration. It extends spatie/laravel-medialibrary's base Media
 * and makes the model UUID-native: `uuid` is the primary key (matching the `uuidMorphs`
 * media table this package ships), so a media record is referenced by a bare uuid across
 * the estate (the `melody_ref` cell-reference contract, blast-radius #4).
 *
 * This is a subclass point: a host (Tower) extends this and binds its subclass via
 * `config('beam.media.model')`. The base is deliberately thin — host-specific concerns
 * (source-meta scopes, flags/tags, a content-url accessor) live on the host subclass, not
 * here. beam-media names no host; the dependency runs one way (host → beam-media).
 *
 * Authorization is declared HERE, on the model, and bound by this package's provider
 * (api-surface-coherence 147, the shape 135 landed for `Hook`): `#[UseCascadePolicy]` gives
 * `Gate::getPolicyFor(Media::class)` a real answer, so the four consumers of a policy stop reading its
 * absence four different ways. Laravel resolves a policy through `class_parents`, so a host's subclass
 * (tower's Media) is covered by this one binding without re-declaring it.
 */
#[UseCascadePolicy]
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

    /**
     * Write-side pin of the durable morph token (ADR-0118: the alias IS the permission-token prefix).
     *
     * This class and a host's subclass ride the SAME `beam_media` row, so they are tier variants of one
     * particle. The host owns the read-side `Relation::morphMap` entry for `media` and points it at ITS
     * subclass (tower does), so this base class is absent from the map at such a host and
     * `Model::getMorphClass()` — an exact class-string match — would fall back to the FQCN: every
     * `*_type` column pointing at a base-instantiated row, and every permission token the cascade mints
     * for `Media::class`, would read `splicewirebeammediamodelsmedia`. Pinning the literal keeps the
     * token `media` whichever tier instantiated the row. Same shape as satellite-knowledge's `Rule`.
     */
    public function getMorphClass(): string
    {
        return 'media';
    }
}
