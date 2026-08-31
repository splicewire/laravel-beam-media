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
    // particle-write-surface ticket 02 — was `ability: null`. Measured gate-closed at the flagship on
    // 2026-08-27: any authenticated tenant member, holding no role and no entitlement, could trigger
    // the host-bound ingest pipeline on ANY media row on the tenant connection (`scope` is `null` on
    // this resource). At the flagship that pipeline is Tower's `TowerMediaIngestor` — embeddings,
    // graph triples, silo filing — so the reachable act both mutates silo membership and spends
    // embedding tokens.
    //
    // ## This op raises TWO questions and only one of them belongs here
    //
    // **Authorization — yes, and it is this line.** The ingestor mutates, against the media's owning
    // Fragment. The declared answer today is the derived permission name, at admin grain, on the
    // spatie permission plane (see {@see \Splicewire\Beam\Market\Extensions\Ops\RemoveInstalledExtension}
    // for the full argument for that token and that plane, and for why `abilityModel` stays `null`
    // rather than `false`).
    //
    // The *fine-grained* answer LANDED (2026-08-31) and it is NOT on this line: `media.ingest` is also
    // a named Gate ability defined by `BeamMediaServiceProvider::bootIngestGate()`, delegating to
    // {@see \Splicewire\Beam\Media\Authorization\MediaIngestGate} — "can you update the model this
    // media is attached to", which at the flagship reaches `fragment.update` / `fragment.own.update`
    // through Fragment's live `#[UseCascadePolicy]`. The two compose in one string: spatie's `before`
    // admits the permission-holder outright and never reaches the ability callback, so the widening
    // can only ever admit someone this line refused.
    //
    // ⚠️ The shape this ticket originally proposed — `Gate::policy(Media::class, MediaPolicy::class)`
    // with a `MediaPolicy::ingest()` — **could never have run.** Laravel's `formatAbilityToMethod()`
    // camelizes on HYPHENS only, so the method it looks for is literally `media.ingest`, which is not
    // a legal method name; the policy is skipped and the callback below it decides. Right rule, wrong
    // registration seam. `MediaIngestGate` states both that and the blast-radius argument in full.
    //
    // **Cost — yes, and it does NOT belong here.** Ingest is unbounded and repeatable: the same media
    // can be ingested any number of times and each pass spends tokens. That is a quota, and a quota
    // belongs on the mount as a `throttle:`. **An ability answers "may you", never "how often."** Do
    // not let this gate be mistaken for a budget, and do not widen or narrow it to serve one.
    //
    // The quota landed alongside the widening, as the named limiter `beam-media.ingest` registered by
    // `BeamMediaServiceProvider::bootIngestRateLimiter()` — two stacked axes (per-actor for fairness,
    // per-tenant for budget), configured under `beam.media.ingest.throttle`. It is registered by this
    // package and ATTACHED by the host, because there is no mount-time middleware seam:
    // `ParticleMounter::op()` reads only `method`/`idConstraint`/`name`/`streams`/`alias` out of its
    // `$options`. The mount is therefore a group:
    //
    //     Route::middleware('throttle:beam-media.ingest')->group(function (): void {
    //         Particle::ops('media', 'media', [IngestMedia::class]);
    //     });
    //
    // ⚠️ A HOST MUST DEFINE THIS NAME. An ability outside a host's declared universe is undefined, and
    // undefined is denied. The one host mounting this op (`~/Herd/splicewire-app`) seeds it to `Admin`
    // in `database/seeders/PermissionsSeeder.php`; a bare beam-media install binds no ingestor at all,
    // so there `handle()` was already a no-op and the gate costs nothing.
    ability: 'media.ingest',
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
