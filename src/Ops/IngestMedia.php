<?php

namespace Splicewire\Beam\Media\Ops;

use Illuminate\Http\Request;
use Splicewire\Beam\Media\Contracts\MediaIngestor;
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
 * FILLED (HTTP-12): the op delegates to the host-bound {@see MediaIngestor} port. Its input is the request
 * carrying the validated upload InputData fields (`FragmentAttachInputData` / `PdfFragmentUploadInputData`
 * — the ingest params: also_ingest/silo_ids and auto_chunk_size/attach_only/source). beam-media stays
 * Fragment-agnostic — it owns the generic operation, NOT the pipeline (embeddings / graph-triples / silo
 * filing live in the Tower host, which binds the `MediaIngestor`). When no host has bound one, `handle` is
 * a safe no-op returning the media unchanged (a bare beam-media install has no pipeline to run).
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
        // Delegate to the host-bound ingest pipeline (Tower's TowerMediaIngestor). Unbound ⇒ safe no-op:
        // a bare beam-media install has no pipeline, so the op returns the media unchanged.
        if (! app()->bound(MediaIngestor::class)) {
            return $media;
        }

        return app(MediaIngestor::class)->ingest($media, $request);
    }
}
