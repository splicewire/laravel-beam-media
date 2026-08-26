<?php

namespace Splicewire\Beam\Media\Data;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Particle\ParticleController;

/**
 * The media particle's declared write body (api-surface-coherence 65).
 *
 * `MediaData` used to declare no `input:` at all, on the reading that "this resource has no body schema
 * yet". The effect was the opposite of the intent: {@see ParticleController::parseInput()} hands the RAW
 * `Request` through when `input:` is null, and `toAttributes()` snake-maps EVERY body key onto the model —
 * so with `Media`'s `$guarded = []`, `model_type`/`model_id` (the `uuidMorphs('model')` owner) and `disk`
 * were both settable by the caller. *Undeclared* meant *accept anything*.
 *
 * This class is the declaration that closes it, and the columns it OMITS are the load-bearing part:
 *
 *   - **`model_type` / `model_id`** — the polymorphic owner. Never a body field. Under the fragment-relative
 *     mount it comes structurally from the bound parent (and beam-core now strips it from the payload
 *     unconditionally, {@see ParticleController::withoutStructuralColumns()}); there is no other legitimate
 *     way to name an owner, which is exactly why the flat `POST /media` is retired rather than given one.
 *   - **`disk` / `conversions_disk`** — storage PLACEMENT, decided by the server. The tenancy bootstrapper
 *     already switches `media-library.disk_name` per tenant, so a caller-supplied disk could only ever be
 *     wrong or hostile: paired with `file_name` it addresses a file the caller never uploaded, and
 *     `GET /media/{uuid}/op/download` streams whatever it names. {@see MediaData::prepare()} stamps the
 *     configured disk on a fresh record instead.
 *   - **`manipulations` / `generated_conversions` / `responsive_images`** — spatie's own conversion
 *     bookkeeping, written by the media library, not by an API caller. `prepare()` seeds them on create
 *     (they are NOT NULL with no default).
 *   - **`uuid`** — the primary key, which the route and the model own.
 *
 * What remains is metadata: what the file is called and how it is filed. All properties are nullable and
 * {@see toModelAttributes()} skips the nulls, so a `PUT` that names one field edits one field — the estate's
 * partial-update convention (`AgentInputData` and the ticket-63/64/66 sweep DTOs do the same).
 */
class MediaWriteInputData extends Data
{
    public function __construct(
        /** Display name for the media record (independent of the stored file name). */
        public ?string $name = null,
        /** The spatie media collection this record is filed under. */
        public ?string $collectionName = null,
        /** The stored file name. */
        public ?string $fileName = null,
        /** The media's MIME type. */
        public ?string $mimeType = null,
        /** File size in bytes. */
        public ?int $size = null,
        /** The caller-authored metadata bag spatie exposes on every media record. */
        public ?array $customProperties = null,
        /** Ordering within the collection. */
        public ?int $orderColumn = null,
    ) {}

    /**
     * The write map (the beam `toModelAttributes()` convention). Null-valued properties are OMITTED rather
     * than written, so an update touches only what the caller named.
     *
     * @return array<string, mixed>
     */
    public function toModelAttributes(): array
    {
        $map = [
            'name' => 'name',
            'collectionName' => 'collection_name',
            'fileName' => 'file_name',
            'mimeType' => 'mime_type',
            'size' => 'size',
            'customProperties' => 'custom_properties',
            'orderColumn' => 'order_column',
        ];

        $attributes = [];

        foreach ($map as $property => $column) {
            if ($this->{$property} !== null) {
                $attributes[$column] = $this->{$property};
            }
        }

        return $attributes;
    }
}
