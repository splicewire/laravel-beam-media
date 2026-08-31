<?php

namespace Splicewire\Beam\Media\Tests;

/**
 * The owner's own `update` rule — the flagship's is `fragment.update` / `fragment.own.update`, reached
 * through `Fragment`'s `#[UseCascadePolicy]`.
 *
 * It grants to a LIST rather than to a single user on purpose: the interesting throttle questions are
 * about two colleagues who can both legitimately update the same owner (does one exhausting their
 * bucket deny the other? does the tenant ceiling still catch them together?), and a single-owner
 * fixture cannot pose them without moving the authorization mid-test.
 */
class IngestOpOwnerPolicy
{
    public function update(IngestOpUser $user, IngestOpOwner $owner): bool
    {
        return in_array((string) $user->id, $owner->editorIds(), true);
    }
}
