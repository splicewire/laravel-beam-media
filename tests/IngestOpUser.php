<?php

namespace Splicewire\Beam\Media\Tests;

use Illuminate\Foundation\Auth\User as AuthUser;
use Spatie\Permission\Traits\HasRoles;

/**
 * The acting principal. `HasRoles` is what makes the spatie permission plane — the COARSE half of
 * `media.ingest`'s gate — reachable at all; a test that does not boot spatie's provider simply never
 * exercises it and falls through to the fine gate, which is the point.
 */
class IngestOpUser extends AuthUser
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}
