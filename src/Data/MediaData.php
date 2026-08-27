<?php

namespace Splicewire\Beam\Media\Data;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Splicewire\Beam\Data\Data;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Media\Models\Media;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * The generic Media particle projection (HTTP-10 / ADR-0178). A fully attribute-declared
 * `#[ParticleResource]` served by beam-core's generic {@see ParticleController}
 * — no bespoke MediaController. This is the FIRST real attribute-path particle in the estate and the wire
 * side of the `MediaRef` cell-reference contract (blast-radius #1/09): the spine `Wire\MediaRef` +
 * client `Data\MediaRef` mirror this shape by convention (they are downstream and cannot require
 * beam-media — the fold was rejected as topology-illegal), NOT by sharing this class.
 *
 * Emits `{ uuid, file_name, content_url, mime_type, size }`:
 *   - `uuid`         the primary key AND the bare cell reference a music cell's `melody_ref` carries
 *                    (the model is UUID-native, {@see Media}).
 *   - `file_name`    the stored file name (sync-read metadata, contract #7).
 *   - `content_url`  the media-library storage URL — `$media->original_url` (contract #2 / fork ③: the
 *                    vestigial `content_url` ROUTE accessor is dropped; the wire field now equals Spatie's
 *                    storage URL directly). We ALSO carry `original_url` on the wire so the existing
 *                    `MediaRef::fromArray` `original_url`-first parse stays a no-op — the dual-name wire
 *                    contract (#2) holds with MediaRef UNCHANGED.
 *   - `mime_type`,   sync-read metadata (contract #7).
 *     `size`
 *
 * Fragment-agnostic BY CONSTRUCTION: this class (and beam-media) never imports Tower's `Fragment`. The
 * fragment-relative mount (`/fragments/{fragment}/media`) lives in the Tower host, not here — beam-media
 * stays the generic media arm with no up-edge to a satellite/host.
 */
#[ParticleResource(
    key: 'media',
    backing: Media::class,
    // `data:` omitted — this class IS the read projection (single-class default); the convention
    // `project()` below takes precedence.
    //
    // `input:` DECLARED (api-surface-coherence 65). It used to be omitted, on the reading that "a
    // standalone metadata-only create/update carries no JSON body schema yet". `input: null` does not
    // mean *no body* on the REST axis — `ParticleController::parseInput()` returns the raw Request and
    // `toAttributes()` snake-maps every key onto a `$guarded = []` model, so it meant *any body*, and
    // `model_type`/`model_id`/`disk` were all forgeable. {@see MediaWriteInputData} says what a media
    // write accepts, and its OMISSIONS are the fix — see its docblock.
    input: MediaWriteInputData::class,
    //
    // `filterable: false` (deviates from the 10-spec's `filterable: true` — a deliberate, flagged
    // engineering correction): beam-core's `ParticleController::index` branches on `filterable` — a
    // `filterable: true` index rides the data-filters BUILDER (`hydrator->query($key)`) and, by
    // construction, IGNORES the bound-relative query, so the fragment-relative mount
    // (`/fragments/{fragment}/media`) would list EVERY media row instead of the fragment's. The
    // relative-scoping contract (HTTP-10 acceptance #4 — the FK is structural, the index scoped
    // THROUGH `$fragment->media()`) is load-bearing; `filterable: false` is the only setting under which
    // the relative index scopes correctly (it takes the `relativeBaseQuery` path). It also avoids
    // standing up a `media` data-filters resource that isn't in this ticket's scope. Media has no facet
    // filters today, so no capability is lost. `defaultSort` (null ⇒ `created_at`) orders the flat list.
    filterable: false,
    perPage: 20,
    // Display singular for docs/titles: the inflector singularizes `media` to "Medium", so the download
    // op titled "Medium Download" in the generated API docs. `media` is a mass noun here — one record is
    // still "Media" — and the declaration is the honest place to say so. Display-only: `label` stays
    // empty, so the resource remains REST-only (not framed).
    singularLabel: 'Media',
)]
#[MapOutputName(SnakeCaseMapper::class)]
class MediaData extends Data
{
    public function __construct(
        public string $uuid,
        public ?string $fileName,
        public ?string $contentUrl,
        public ?string $originalUrl = null,
        public ?string $mimeType = null,
        public ?int $size = null,
    ) {}

    /**
     * The pre-write hook (the beam `prepare()` convention) — the server's half of the write, stamped on a
     * FRESH record only (api-surface-coherence 65).
     *
     * These are the columns {@see MediaWriteInputData} deliberately does not accept. `disk` is storage
     * PLACEMENT: the tenancy bootstrapper already switches `media-library.disk_name` to the tenant's own
     * disk, so the configured value is the right answer and a caller-supplied one could only be wrong or
     * hostile. The three conversion-bookkeeping columns are NOT NULL with no default and belong to the media
     * library, not to an API caller.
     *
     * An existing record is left alone: an update never re-places a stored file.
     */
    public static function prepare(Media $media, mixed $input, mixed $actor): void
    {
        if ($media->exists) {
            return;
        }

        $media->disk ??= config('media-library.disk_name');
        $media->collection_name ??= 'default';
        $media->manipulations ??= [];
        $media->custom_properties ??= [];
        $media->generated_conversions ??= [];
        $media->responsive_images ??= [];
    }

    /**
     * The row → Data projector (the beam `project()` convention). Reads Spatie's `original_url`
     * appended attribute for the storage URL and mirrors it onto BOTH `content_url` (the app wire
     * field) and `original_url` (so the `MediaRef` `original_url`-first parse is a no-op — MediaRef
     * unchanged).
     */
    public static function project(Media $media): self
    {
        return new self(
            uuid: (string) $media->uuid,
            fileName: $media->file_name,
            contentUrl: $media->original_url,
            originalUrl: $media->original_url,
            mimeType: $media->mime_type,
            size: $media->size,
        );
    }
}
