<?php

namespace Splicewire\Beam\Media\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Media\Ops\DownloadMedia;

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
 * What remains is metadata: what the file is called and how it is filed.
 *
 * ## The three input states, and which fields can tell them apart
 *
 * A PATCH has three things it can say about a field — *absent* ("leave it alone"), *present-and-null*
 * ("clear it") and *present-with-a-value*. A property written `public ?T $x = null` can only ever express
 * TWO of them: `Spatie\LaravelData\DataPipes\DefaultValuesDataPipe` checks `hasDefaultValue` BEFORE
 * `type->isOptional`, so the declared default wins and an absent field arrives as `null`, indistinguishable
 * from a submitted one. On a `!== null` gate that collapse is silent and one-directional — the column can
 * be set and can never be cleared.
 *
 * So the split below is deliberate and per-field, not a sweep:
 *
 *   - **`order_column`** is `int|Optional|null` with NO `= null` default (the default is the sentinel
 *     itself). Absent ⇒ untouched · present-and-null ⇒ written as null, which is spatie's "unordered" ·
 *     value ⇒ written. Removing the `= null` is the whole fix; putting it back makes the `Optional` arm
 *     unreachable again.
 *   - **`name`, `collection_name`, `file_name`, `size`, `custom_properties`** stay on the `!== null` gate.
 *     Every one is NOT NULL in `create_media_table`, so "clear" is not an affordance being withheld — it is
 *     a constraint violation. Dropping the null is the correct no-op.
 *   - **`mime_type`** is nullable in the column and is STILL held back, on the security axis rather than the
 *     schema one: it is the `Content-Type` the `op/download` stream carries ({@see DownloadMedia}
 *     hands the record to spatie's `toResponse()`), and stripping it hands the browser a sniffing decision.
 *     Clearing a MIME type is not a caller intent anyone has; a no-op is the safer reading of a null.
 *
 * Nothing here widens what the DTO ACCEPTS — the property list is unchanged, so none of the omissions above
 * are reopened. `Optional` changes only how an already-accepted field's null is read.
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
        /**
         * Ordering within the collection — nullable in the column, and CLEARABLE. `int|Optional|null` with
         * no `= null` default, so an absent field is the `Optional` sentinel and an explicit null is a real
         * null that reaches the column. See the class docblock; do not restore the default.
         */
        public int|Optional|null $orderColumn = new Optional,
    ) {}

    /**
     * The write map (the beam `toModelAttributes()` convention).
     *
     * Two gates, deliberately. Ordinary fields drop their nulls, so an update touches only what the caller
     * named. `order_column` is gated on PRESENCE instead — an explicit null is a caller clearing the
     * position, and that is the one thing the null-dropping gate cannot express.
     *
     * Explicit per-field checks, never `get_object_vars`, which would leak `Optional` sentinels onto the
     * write.
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
        ];

        $attributes = [];

        foreach ($map as $property => $column) {
            if ($this->{$property} !== null) {
                $attributes[$column] = $this->{$property};
            }
        }

        // Absent ⇒ leave the column alone. Present ⇒ write it, INCLUDING a null, which clears the
        // record's explicit position (spatie reads a null `order_column` as unordered).
        if (! $this->orderColumn instanceof Optional) {
            $attributes['order_column'] = $this->orderColumn;
        }

        return $attributes;
    }
}
