<?php

namespace Splicewire\Beam\Media\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Splicewire\Beam\Media\Contracts\MediaIngestor;
use Splicewire\Beam\Models\HasStatuses;

/**
 * A unit of work dispatched to a party OUTSIDE this application that produces a MEDIA ARTIFACT — the
 * durable record of outsourced media work. Read the migration's charter docblock before extending this:
 * it states the boundary as an exclusion (a producer of a model, a document or a report does not belong
 * here) and that exclusion is what lets one table serve every medium.
 *
 * Generalized from Tower's `video_jobs`, whose own docblock claimed *"video is the one modality that
 * cannot reuse the synchronous request/response shape"* — retired by music being the second.
 *
 * THE ARTIFACT IS THE `media` RELATION. `result_url` is the vendor's short-lived URL (provenance), not
 * storage; the durable bytes are ingested into a {@see Media} record through this package's
 * {@see MediaIngestor}. That is why five of `video_jobs`' columns
 * (`result_disk` / `result_path` / `mime` / `file_size`, and `resolution` beside them) do not appear here
 * — they were a hand-rolled media row standing next to the real one this package already owns.
 *
 * STATUS IS BEAM'S OWN, via {@see HasStatuses} (`spatie/laravel-model-status`, already a beam-core
 * dependency) rather than a `status` column and deliberately NOT `WorkflowManaged` — `laravel-beam-workflows`
 * requires `laravel-beam`, so beam cannot depend on workflows without a cycle. A host that wants workflow
 * governance layers it on its own subclass.
 *
 * THIS CLASS IS A SUBCLASS POINT, and the seam is load-bearing rather than decorative. `handle` is
 * deliberately opaque `array` here: beam-media must not know what a `rushing/laravel-prism-plus` job is,
 * because naming that type would put a prism-plus edge on the media arm and therefore on every beam host.
 * A host that drives an async vendor subclasses this and adds the typed rehydration there — exactly as
 * Tower's `VideoJob::handle()` does.
 *
 * `config('beam.media.job_model')` is how a host names that subclass to this package, and it is currently
 * UNCLAIMED, deliberately: Tower's only async media driver is video, so its subclass is scoped to one
 * medium, and binding a medium-scoped class as THE job model would mint every other medium's rows as that
 * class and then hide them behind its scope. The seam is for a host with a medium-agnostic driver; a
 * per-medium subclass is named by its own call sites instead. Nothing in this package resolves the key yet.
 *
 * @property string $id
 * @property string $medium
 * @property string|null $prompt
 * @property string $model
 * @property string $provider
 * @property string|null $provider_job_id
 * @property string|null $run_id
 * @property array<string, mixed>|null $handle
 * @property array<string, mixed>|null $params
 * @property string|null $result_url
 * @property int|null $seconds
 * @property string|null $error
 * @property int $poll_attempts
 * @property Carbon|null $completed_at
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string|null $produced_type
 * @property string|null $produced_id
 */
class ProviderMediaJob extends Model implements HasMedia
{
    use HasStatuses;
    use HasUuids;
    use InteractsWithMedia;

    protected $table = 'provider_media_jobs';

    protected $guarded = [];

    protected $casts = [
        'handle' => 'array',
        'params' => 'array',
        'seconds' => 'integer',
        'poll_attempts' => 'integer',
        'completed_at' => 'datetime',
    ];

    /** Who this job belongs to in the host's own vocabulary — a Song, a Composition, a Fragment. */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The node the produced artifact was attached to on completion — a timeline `Clip`, say. Null at
     * submit, which is when the row is written, and that is the correct reading rather than a gap.
     */
    public function produced(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether anything outside this application is still expected to report back.
     *
     * The presence of `provider_job_id` IS the async indicator: it is the reconcile key, so having none
     * means there is nothing to reconcile. A synchronous vendor (ElevenLabs music returns the finished
     * bytes in one request/response) yields no job id and no handle, and such a row is BORN TERMINAL —
     * no synthetic already-completed handle is minted to make it look async.
     */
    public function isAsync(): bool
    {
        return $this->provider_job_id !== null;
    }
}
