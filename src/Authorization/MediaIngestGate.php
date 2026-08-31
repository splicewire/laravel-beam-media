<?php

namespace Splicewire\Beam\Media\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Media\Models\Media;
use Splicewire\Beam\Media\Ops\IngestMedia;

/**
 * The FINE-GRAINED half of `media.ingest`'s gate (particle-write-surface ticket 02) — "may this actor
 * ingest THIS media row?", answered by delegating to the row's polymorphic OWNER.
 *
 * The coarse half is the `media.ingest` permission row on the spatie plane, declared by
 * {@see IngestMedia}. This class is what a non-permission-holder falls through to: a media record is a
 * sidecar on another model (spatie's `uuidMorphs('model')`), so the honest rule is *"can you update the
 * thing this file is attached to"* — the exact shape `Splicewire\Tower\Policies\ModelStatusPolicy` uses
 * for the same sidecar-on-a-morph problem. At the flagship the owner is a `Fragment`, whose
 * `#[UseCascadePolicy]` already answers `update` through `fragment.update` / `fragment.own.update`.
 *
 * This WIDENS the gate; it never narrows it. spatie's `Gate::before` admits the permission-holder
 * outright and never reaches here, so adding this cannot refuse anyone who was previously admitted.
 *
 * ## ⚠️ Why this is `Gate::define`, and why a `Gate::policy(Media::class, …)` would have been DEAD CODE
 *
 * Two independent reasons, and the second is the decisive one because it is not a judgement call.
 *
 *  1. **Blast radius.** `laravel-beam-accounts`' `WiresAuthorization` records the measured failure:
 *     `Gate::getPolicyFor()` becoming non-null routes ability lookups about that model into the policy
 *     class, and the beam Frame write path asks `create`/`update` about every mounted resource — `media`
 *     is mounted `show`/`update`/`destroy` at the flagship. A named ability has no blast radius at all.
 *
 *  2. **It could never have been consulted.** Laravel's `Gate::formatAbilityToMethod()` is
 *     `str_contains($ability, '-') ? Str::camel($ability) : $ability` — it camelizes on HYPHENS ONLY.
 *     The declared ability is `media.ingest`, so the method it looks for on a policy is literally
 *     `media.ingest`, which is not a legal PHP method name; `resolvePolicyCallback()` therefore returns
 *     `false` and the policy is skipped. A `MediaPolicy::ingest()` would never have been called by the
 *     check {@see ParticleOperationController} actually performs. The
 *     ticket's proposed shape was right about the RULE and wrong about the registration seam.
 *
 * `Gate::define()` is last-write-wins by name and this package boots before a host's own
 * `AuthServiceProvider`, so a host that wants a different rule simply redefines it.
 */
class MediaIngestGate
{
    /**
     * The parameter is non-nullable on purpose. Laravel's `Gate::resolveAuthCallback()` runs
     * `canBeCalledWithUser()` over a defined ability and SKIPS a callback whose user parameter cannot
     * accept null — so a guest never reaches this method and falls through to the gate's deny-default
     * rather than being measured against an owner they could never have.
     */
    public function ingest(Authenticatable $user, Media $media): bool
    {
        $owner = $this->owner($media);

        // No owner to delegate to — the morph is empty, its type is not resolvable, or the row it
        // pointed at is gone. DENY: an unresolvable subject must never read as permission granted.
        // (The permission-holder was already admitted by spatie's `before` and never arrives here, so
        // denying an orphan costs an administrator nothing.)
        if ($owner === null) {
            return false;
        }

        return Gate::forUser($user)->allows('update', $owner);
    }

    /**
     * The media's owning model, or null when there isn't one that can be authorized against.
     *
     * ⚠️ The `class_exists()` guard is not defensive padding. `MorphTo` resolves an unmapped
     * `model_type` to the string itself and then instantiates it, so reading `$media->model` on a row
     * whose type is absent from the morph map raises an `Error`, not a null — and an `Error` inside an
     * authorization callback is a 500 on a security path. Media rows carry types written by whoever
     * created them, including rows predating a morph-map entry, so this is a live shape rather than a
     * hypothetical.
     */
    private function owner(Media $media): ?Model
    {
        $type = $media->getAttribute('model_type');

        if (! is_string($type) || $type === '') {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_a($class, Model::class, true)) {
            return null;
        }

        $owner = $media->model;

        return $owner instanceof Model ? $owner : null;
    }
}
