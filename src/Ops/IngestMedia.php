<?php

namespace Splicewire\Beam\Media\Ops;

use Illuminate\Http\Request;
use Splicewire\Beam\Media\Models\Media;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;

/**
 * The ingest side-effect operation (HTTP-10 / asset 13 §4). A `kind: Write` `#[ParticleOp]` mounted at
 * `POST /media/{uuid}/op/ingest` that layers the fragment-upload INGEST PIPELINE trigger on top of a
 * media row that already exists (created structurally through the fragment-relative `store`).
 *
 * The macros solve the ATTACH itself (fork: Attach) — `POST /fragments/{fragment}/media` creates the Media
 * through `$fragment->media()` (the FK set structurally by the bound relative, never a body field). What
 * the relative `store` does NOT absorb is the ingest side-effects the two retired upload endpoints carried
 * beyond "create + associate" — `also_ingest`/`silo_ids` (attach) and `auto_chunk_size`/`attach_only`/
 * `source` (uploadPdf). Those ride HERE as a Write op invoked after creation, not a hand-rolled attach
 * controller.
 *
 * PLACEHOLDER handler (ticket 12): the op's input DTO is ticket 12's upload InputData
 * (`FragmentAttachInputData` / `PdfFragmentUploadInputData` — the `UploadedFile` part the relative `store`
 * consumes, plus the ingest params). Until ticket 12 fills it, `handle` is a no-op stub that returns the
 * media unchanged — the op EXISTS (declared + discoverable + mountable, proving the Write-kind path), but
 * runs no ingest yet. Ticket 12 replaces the body with the real pipeline dispatch.
 */
#[ParticleOp(
    resource: 'media',
    name: 'ingest',
    kind: OperationKind::Write,
    model: Media::class,
)]
class IngestMedia
{
    public static function handle(Media $media, Request $request, mixed $actor): Media
    {
        // ticket 12: dispatch the ingest pipeline from the upload InputData (also_ingest / silo_ids /
        // auto_chunk_size / attach_only / source). Stub for now — the op is declared and mountable; the
        // side-effect body lands with ticket 12's InputData.
        return $media;
    }
}
