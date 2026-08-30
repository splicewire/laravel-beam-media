<?php

namespace Splicewire\Beam\Media\Ops;

use Illuminate\Http\Request;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Media\Delivery\MediaFileDelivery;
use Splicewire\Beam\Media\Models\Media;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Routing\IdConstraint;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The FIRST real `#[ParticleOp]` consumer in the estate (HTTP-10 / ADR-0160). A `kind: Read` op mounted at
 * `GET /media/{uuid}/op/download` (via `Route::particleOps`) that streams the media file as an attachment
 * — Spatie's `Media::toResponse()` returns a Symfony {@see StreamedResponse}, and beam-core's
 * {@see ParticleOperationController::invoke} passes a Read op's return value
 * through UNTOUCHED (the default match arm), so the binary stream flows out unchanged (contract #5 — binary
 * stays a StreamedResponse; it is NOT flattened into the JSON envelope).
 *
 * Replaces the retired bespoke `MediaController::download` + the vestigial `media.content` inline route
 * (fork ③): the URL moves from `/media/{uuid}/download` to `/media/{uuid}/op/download` (the ③ trace found no
 * live caller). The op class is thin and self-contained — declaration + handler co-located, registered with
 * no provider glue by `Route::particleOps([DownloadMedia::class])`.
 */
#[ParticleOp(
    resource: 'media',
    name: 'download',
    kind: OperationKind::Read,
    model: Media::class,
    // `input: false` — this operation accepts NO caller payload, declared rather than implied
    // (api-surface-coherence 68). Measured, not assumed: `handle()` never touches `$request`.
    // Enforced by `ParticleOperationController::rejectInput()`, so a request that carries one is
    // a 422 instead of a silent ignore.
    input: false,
    // The verb and the `{id}` shape moved off the mount and onto the declaration
    // (particle-operation-surface 14). Both were previously restated in
    // `~/Herd/splicewire-app/routes/tenant.php` as `['method' => 'get', 'idConstraint' => 'uuid']`,
    // where a host had to know that a media download is an idempotent read of a uuid-keyed row —
    // two facts this package owns and that host does not.
    method: HttpMethod::Get,
    idConstraint: IdConstraint::Uuid,
    // What this operation puts on the wire (particle-operation-surface 14). `output:` names a Data
    // class and is structurally silent about an endpoint that returns BYTES, so this published an
    // untyped 200 with no media type at all. {@see MediaFileDelivery} carries why the type is
    // `application/octet-stream` rather than the record's real one, and why there is no format axis.
    delivery: MediaFileDelivery::class,
)]
class DownloadMedia
{
    public static function handle(Media $media, Request $request, mixed $actor): StreamedResponse
    {
        return $media->toResponse($request);
    }
}
