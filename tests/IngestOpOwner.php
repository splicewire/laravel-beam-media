<?php

namespace Splicewire\Beam\Media\Tests;

use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Stands in for the flagship's `Fragment` — the model a media row hangs off, and the thing the fine
 * gate delegates to. It is deliberately NOT a beam model: the delegation must work for whatever a host
 * attaches media to, and a test that used a beam type would be proving something narrower.
 */
class IngestOpOwner extends EloquentModel
{
    protected $table = 'owners';

    public $timestamps = false;

    protected $guarded = [];

    /** @return list<string> */
    public function editorIds(): array
    {
        return array_values(array_filter(explode(',', (string) $this->editor_ids), fn (string $id) => $id !== ''));
    }

    public function addEditor(int|string $userId): void
    {
        $this->update(['editor_ids' => implode(',', [...$this->editorIds(), (string) $userId])]);
    }
}
