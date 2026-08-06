<?php

namespace Splicewire\Beam\Media\Contracts;

use Illuminate\Http\Request;
use Splicewire\Beam\Media\Models\Media;
use Splicewire\Beam\Media\Ops\IngestMedia;

/**
 * The host-bound ingest seam behind the {@see IngestMedia} `#[ParticleOp]`
 * (HTTP-12). beam-media stays Fragment-agnostic: it owns the generic "layer ingest side-effects onto an
 * already-created media row" OPERATION, but not the pipeline itself (embeddings / graph-triples / silo
 * filing all live in the satellite host, Tower). A host binds an implementation of this port; when none is
 * bound the op is a safe no-op (a bare beam-media install has no pipeline to run).
 *
 * The op's INPUT (HTTP-12 acceptance #2) is the request that carries the validated upload InputData fields
 * (`FragmentAttachInputData` / `PdfFragmentUploadInputData` — the ingest params the retired upload
 * endpoints carried beyond "create + associate": also_ingest/silo_ids and auto_chunk_size/attach_only/
 * source). The host ingestor reads them off the request and dispatches its pipeline.
 */
interface MediaIngestor
{
    /**
     * Run the host ingest pipeline for a media row that already exists (created structurally through the
     * fragment-relative `store`). Returns the (possibly refreshed) media.
     */
    public function ingest(Media $media, Request $request): Media;
}
