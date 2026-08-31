<?php

namespace Splicewire\Beam\Media\Ops;

use Illuminate\Http\Request;
use Splicewire\Beam\Media\Contracts\MediaIngestor;
use Splicewire\Beam\Media\Models\Media;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Routing\IdConstraint;

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
    // `input:` is DELIBERATELY LEFT UNDECLARED — the one operation api-surface-coherence 68's sweep
    // skipped on purpose, and the reason is structural rather than unfinished work.
    //
    // beam-media owns the OPERATION; the host owns what its request MEANS. `handle()` hands the whole
    // `$request` to the bound `MediaIngestor` port, and in the flagship host that is Tower's
    // `TowerMediaIngestor`, whose own docblock says it plainly: *"the ingest op's input is the upload
    // InputData"*. Any class named here would be true in that host and a LIE in another — and a bare
    // beam-media install binds no ingestor at all, so the honest declaration there is `false`, which is
    // the opposite answer. `false` is therefore not the safe default it is on every other op in this
    // package: it would reject the very payload the host's pipeline exists to read.
    //
    // What this wants is a HOST-CONTRIBUTED input declaration on a package-owned op — the same
    // package-side refusal api-surface-coherence 17 hit — and that is a decision, not a line in a
    // sweep.
    //
    // ⚠️ It is therefore NOT counted as outstanding. api-surface-coherence 117 gave the carve-out its
    // spelling: `media.ingest` is named in `Splicewire\Beam\Doctor\UndeclaredInputAudit::ACKNOWLEDGED`
    // with this reason, so `splicewire:beam:doctor` reports it as ACKNOWLEDGED rather than as unfinished
    // work — and reports it as STALE the day this declaration gains an input, so the carve-out cannot
    // outlive its reason. The acknowledgement lives in the audit deliberately: no fourth declaration
    // state enters `#[ParticleOp]` for the sake of one operation.
    // The `{id}` shape moved off the mount and onto the declaration
    // (particle-operation-surface 14) — every host mounting this op restated `'idConstraint' => 'uuid'`
    // for a key type the model already knows.
    idConstraint: IdConstraint::Uuid,
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
