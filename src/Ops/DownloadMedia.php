<?php

namespace Splicewire\Beam\Media\Ops;

use Illuminate\Http\Request;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Media\Models\Media;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
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
)]
class DownloadMedia
{
    public static function handle(Media $media, Request $request, mixed $actor): StreamedResponse
    {
        return $media->toResponse($request);
    }
}
