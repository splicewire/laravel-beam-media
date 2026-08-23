<?php

namespace Splicewire\Beam\Media\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Spatie\MediaLibrary\HasMedia;
use Splicewire\Beam\Media\Models\ProviderMediaJob;
use Splicewire\Beam\Models\HasStatuses;

/**
 * The `provider_media_jobs` substrate. These assertions pin the decisions that are cheapest to undo by
 * accident — the absent columns, the opaque handle, the async indicator — rather than restating the
 * schema. Each one names the failure it prevents.
 */
class ProviderMediaJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadJobsMigration();
    }

    /**
     * The morph alias. Registered while the table was still empty in every schema in the estate
     * (voice-profile 29's install was the first host to carry this package at all) — an alias is free
     * to choose exactly once, and after the first row is written moving it costs a data migration
     * across every `*_type` column that points here AND every ADR-0118 permission token minted under
     * its prefix.
     */
    public function test_the_base_job_model_has_a_morph_alias(): void
    {
        $this->assertSame('provider_media_job', Relation::getMorphAlias(ProviderMediaJob::class));
    }

    /**
     * The alias is keyed on the BASE class and a host subclass is deliberately NOT swept in. Keying it
     * on `config('beam.media.job_model')` instead would leave the base unaliased whenever a host
     * configured its own class — two tokens for one table, which is the failure the alias exists to
     * prevent. A morph map is alias => class; one alias cannot cover two classes, so the choice is
     * forced rather than preferred.
     *
     * A host names its own subclass from its own provider, which is the estate's worked idiom: tower's
     * own service provider declares `'media'` and `'video_job'` for tower's subclasses while their
     * packages alias what they own. Named in prose, not `{@see}` — beam-media must not import a symbol
     * from a package downstream of it, and pint turns an FQN in a docblock into a real `use`.
     */
    public function test_a_host_subclass_is_left_for_the_host_to_alias(): void
    {
        // getMorphAlias() returns the class-string itself when unmapped — the FQCN fallback.
        $this->assertSame(HostJobModel::class, Relation::getMorphAlias(HostJobModel::class));
        $this->assertSame('provider_media_job', Relation::getMorphAlias(ProviderMediaJob::class));
    }

    public function test_the_row_carries_the_generalized_shape(): void
    {
        foreach ([
            'id', 'medium', 'prompt', 'model', 'provider', 'provider_job_id', 'run_id',
            'handle', 'params', 'result_url', 'seconds', 'error', 'poll_attempts', 'completed_at',
            'owner_type', 'owner_id', 'produced_type', 'produced_id',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('provider_media_jobs', $column),
                "provider_media_jobs must carry [{$column}]."
            );
        }
    }

    /**
     * Five of `video_jobs`' columns were a hand-rolled media row standing beside the real one this
     * package already owns. Re-adding any of them is the regression this test exists to catch: the
     * artifact is the `media` relation, and `result_url` is the vendor's short-lived URL — provenance,
     * not storage.
     */
    public function test_the_artifact_is_a_media_relation_not_columns(): void
    {
        foreach (['result_disk', 'result_path', 'mime', 'file_size', 'resolution'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('provider_media_jobs', $column),
                "provider_media_jobs must NOT carry [{$column}] — the artifact is the media relation."
            );
        }

        $this->assertInstanceOf(HasMedia::class, new ProviderMediaJob);
    }

    /**
     * Status is beam's own (`spatie/laravel-model-status`), not a column and deliberately not
     * `WorkflowManaged` — `laravel-beam-workflows` requires `laravel-beam`, so beam cannot depend on
     * workflows without a cycle.
     */
    public function test_status_is_beams_own_not_a_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('provider_media_jobs', 'status'),
            'Status rides the statuses table, not a column on this row.'
        );

        $this->assertContains(
            HasStatuses::class,
            class_uses_recursive(ProviderMediaJob::class),
        );
    }

    /**
     * The presence of `provider_job_id` IS the async indicator, so a synchronous vendor's row is born
     * terminal with a null handle. Minting a synthetic already-completed handle to make it look async is
     * the dishonesty this pins against.
     */
    public function test_provider_job_id_presence_is_the_async_indicator(): void
    {
        $sync = new ProviderMediaJob(['provider_job_id' => null]);
        $async = new ProviderMediaJob(['provider_job_id' => 'req_abc123']);

        $this->assertFalse($sync->isAsync(), 'A row with no reconcile key has nothing outside to reconcile.');
        $this->assertTrue($async->isAsync());
    }

    /**
     * beam-media must name no `rushing/laravel-prism-plus` type: that edge would land on every beam host.
     * `handle` stays opaque JSON here and the typed rehydration lives on the host's subclass.
     */
    public function test_the_handle_is_opaque_and_names_no_prism_plus_type(): void
    {
        $this->assertSame('array', (new ProviderMediaJob)->getCasts()['handle'] ?? null);

        $source = (string) file_get_contents(
            (new ReflectionClass(ProviderMediaJob::class))->getFileName()
        );

        $this->assertStringNotContainsString(
            'Rushing\\PrismPlus',
            $source,
            'beam-media must not name a prism-plus type — the host subclass owns handle rehydration.'
        );
    }

    /** `params` is sealed at submit — everything sent, nothing measured — so it round-trips verbatim. */
    public function test_params_round_trips_as_the_sealed_submit_payload(): void
    {
        $params = [
            'model' => 'elevenlabs/music',
            'model_id' => 'music_v1',
            'seed' => 42,
            'wire_payload' => ['sections' => [['sectionName' => 'verse']]],
            'steering_fingerprint' => 'sha256:abc',
        ];

        $job = ProviderMediaJob::create([
            'medium' => 'music',
            'model' => 'elevenlabs/music',
            'provider' => 'splicewire',
            'run_id' => 'run_1',
            'params' => $params,
        ]);

        $this->assertSame($params, $job->fresh()->params);
    }

    /**
     * Two rows, one shape, joined on `run_id` — the satellite's take and the platform's vendor call, with
     * `provider` relative to the recorder. Neither side keeps a take history today, which is why the join
     * key has to be a real indexed column rather than an inference.
     */
    public function test_two_rows_join_on_run_id_with_provider_relative_to_the_recorder(): void
    {
        ProviderMediaJob::create([
            'medium' => 'music', 'model' => 'elevenlabs/music',
            'provider' => 'splicewire', 'provider_job_id' => 'run_7', 'run_id' => 'run_7',
        ]);
        ProviderMediaJob::create([
            'medium' => 'music', 'model' => 'elevenlabs/music',
            'provider' => 'elevenlabs', 'provider_job_id' => 'song_xyz', 'run_id' => 'run_7',
        ]);

        $pair = ProviderMediaJob::where('run_id', 'run_7')->pluck('provider')->sort()->values()->all();

        $this->assertSame(['elevenlabs', 'splicewire'], $pair);
    }

    /**
     * The shared/ migration is publish-only (the beam-family convention — the package never
     * loadMigrationsFrom's it at runtime; the host runs the published copy), so the test harness applies
     * the schema itself rather than pretending the package loads it.
     */
    private function loadJobsMigration(): void
    {
        Schema::create('statuses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('reason')->nullable();
            $table->uuidMorphs('model');
            $table->timestamps();
        });

        $migration = require __DIR__.'/../database/migrations/shared/create_provider_media_jobs_table.php.stub';
        $migration->up();
    }
}

/** A host's typed-handle subclass — the `beam.media.job_model` seam, exercised. */
class HostJobModel extends ProviderMediaJob {}
