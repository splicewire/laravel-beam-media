<?php

namespace Splicewire\Beam\Media\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Media\Authorization\MediaIngestGate;
use Splicewire\Beam\Media\Contracts\MediaIngestor;
use Splicewire\Beam\Media\Data\MediaData;
use Splicewire\Beam\Media\Models\Media;
use Splicewire\Beam\Media\Ops\IngestMedia;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * particle-write-surface ticket 02 — `media.ingest` is gated, and this is the regression test.
 *
 * ## Gate posture: CLOSED. Stated, because a security test without it is worth nothing.
 *
 * **No `Gate::before(fn () => true)` anywhere in this class**, and no policy on {@see Media} — Gate is
 * deny-by-default over it, asserted as a control before any finding. The only `Gate::before` in play is
 * the one `spatie/laravel-permission`'s `PermissionRegistrar` installs, which is the mechanism under
 * test. The flagship's production Root escalation is NOT installed here, so unlike the live host this
 * harness has a principal capable of being refused.
 *
 * The one policy registered is on the media's OWNER ({@see IngestOpOwnerPolicy}) — the model the fine
 * gate delegates to, standing in for the flagship's `Fragment`. It is two-sided and asserted as such
 * before anything is read off it.
 *
 * **Both directions are asserted**, and the effect is observed through a spy ingestor rather than
 * inferred from a status code — so "refused" means the pipeline did not run, not merely that the
 * response said 403.
 *
 * ## What was measured before this landed
 *
 * Gate-closed at `~/Herd/splicewire-app`, 2026-08-27: the op declared `ability: null`, the `media`
 * resource declares `scope: null`, and the tenancy middleware on the mount identifies and gates
 * availability without ever authorizing. So any authenticated tenant member holding no role and no
 * entitlement could trigger the host-bound ingestor — at the flagship, Tower's `TowerMediaIngestor`
 * (embeddings, graph triples, silo filing) — against any media row on the tenant connection,
 * repeatedly, spending tokens each time.
 *
 * ⚠️ **The cost half of that finding is not tested here, because it is not this gate's job.** An
 * ability answers "may you", never "how often". Bounding repeat ingests is a throttle on the mount —
 * landed as the named limiter `beam-media.ingest` and covered by {@see IngestMediaThrottleTest}, which
 * is a separate file for the same reason it is a separate mechanism.
 *
 * ## What this file gained on 2026-08-31 (the fine gate)
 *
 * `media.ingest` is now ALSO a named Gate ability delegating to
 * {@see MediaIngestGate} — "can you update the model this media is
 * attached to". Three things are asserted about it, and the third is the one that makes the other two
 * safe to believe:
 *
 *   1. the owner is admitted **without holding the permission**, and a stranger to the owner still is not;
 *   2. an unresolvable owner DENIES rather than crashing (an `Error` on an authorization path is a 500
 *      where the honest answer is 403);
 *   3. a **forged** `model_type`/`model_id` in the request body cannot redirect the gate — the shape
 *      `api-surface-coherence` 65 found on this exact model. Delegating authorization to a forgeable
 *      owner would be worse than not delegating at all, so it is measured, not reasoned about.
 */
class IngestMediaAuthorizationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PermissionServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.providers.users.model', IngestOpUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        (include __DIR__.'/../database/migrations/shared/create_media_table.php.stub')->up();

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('email')->nullable();
        });

        Schema::create('owners', function (Blueprint $t): void {
            $t->id();
            $t->string('editor_ids')->nullable();
        });

        $this->createSpatiePermissionSchema();

        app(ParticleResourceRegistry::class)->registerClass(MediaData::class);

        // The op is host-mounted in the real estate (`splicewire-app`'s `routes/tenant.php`), so this
        // harness mounts the REAL class with beam's own mounter. The declaration under test is the one
        // on `IngestMedia`; nothing here restates it.
        Particle::ops('media', 'media', [IngestMedia::class]);

        // GATE CLOSED: note what is absent — no `Gate::policy(Media::class, …)`, no
        // `Gate::before(fn () => true)`. Nothing in this app can allow anything against a Media except
        // a permission row or the owner delegation under test.
        //
        // The OWNER model does carry a policy, and must: that is the thing being delegated TO, and it
        // is what the flagship's `Fragment` has (via `#[UseCascadePolicy]`). It grants `update` to the
        // owner row's own user and to nobody else, so it is a gate with two sides rather than an open
        // door — asserted as a control below before any finding is read off it.
        Gate::policy(IngestOpOwner::class, IngestOpOwnerPolicy::class);
    }

    // ── Controls, asserted before any finding ───────────────────────────────────────────────────

    public function test_control_the_gate_is_closed_and_a_role_less_member_holds_nothing(): void
    {
        $member = $this->member();
        $media = $this->media();

        $this->assertFalse(Gate::forUser($member)->allows('media.ingest', $media));
        // …and on a bare policy verb too, so the denial is not an artifact of the name's shape.
        $this->assertFalse(Gate::forUser($member)->allows('update', $media));
    }

    public function test_control_the_harness_can_also_admit(): void
    {
        $this->assertTrue(Gate::forUser($this->memberHolding('media.ingest'))->allows('media.ingest', $this->media()));
    }

    // ── The gate, both directions, through the real controller ──────────────────────────────────

    public function test_a_role_less_member_is_refused_and_the_ingest_pipeline_never_runs(): void
    {
        $spy = $this->spyIngestor();
        $media = $this->media();

        $this->actingAs($this->member())
            ->postJson("/media/{$media->uuid}/ingest")
            ->assertForbidden();

        $this->assertSame(0, $spy->calls, 'refused ⇒ no tokens spent, no silo membership mutated');
    }

    public function test_the_permission_holder_is_admitted_and_the_ingest_pipeline_runs(): void
    {
        $spy = $this->spyIngestor();
        $media = $this->media();

        $this->actingAs($this->memberHolding('media.ingest'))
            ->postJson("/media/{$media->uuid}/ingest")
            ->assertOk();

        $this->assertSame(1, $spy->calls, 'admitted ⇒ the effect happened');
    }

    /** A revert to `ability: null` — the residue this ticket closed — fails here. */
    public function test_the_operation_declares_its_ability(): void
    {
        $op = (new ReflectionClass(IngestMedia::class))->getAttributes(ParticleOp::class)[0]->newInstance();

        $this->assertSame('media.ingest', $op->ability);
        // `false` would route to AbilityResolver's subject-free ENTITLEMENT plane, which at the
        // flagship resolves `[]` for every non-Root actor — a guaranteed 403 rather than a gate.
        $this->assertNull($op->abilityModel);
    }

    // ── The OWNER delegation (the fine-grained widening), both directions ────────────────────────

    public function test_control_the_owner_policy_has_two_sides(): void
    {
        $owner = IngestOpOwner::create(['editor_ids' => ($holder = $this->member())->id]);

        $this->assertTrue(Gate::forUser($holder)->allows('update', $owner));
        $this->assertFalse(Gate::forUser($this->member())->allows('update', $owner));
    }

    public function test_the_owner_of_the_attached_model_is_admitted_without_holding_the_permission(): void
    {
        $spy = $this->spyIngestor();
        $owner = IngestOpOwner::create(['editor_ids' => ($actor = $this->member())->id]);
        $media = $this->media(IngestOpOwner::class, $owner->id);

        // The whole point of the widening: this actor holds NO permission row at all, so an admit here
        // can only have come from the owner delegation.
        $this->assertCount(0, $actor->getAllPermissions());

        $this->actingAs($actor)
            ->postJson("/media/{$media->uuid}/ingest")
            ->assertOk();

        $this->assertSame(1, $spy->calls, 'the owner of the attached model may ingest its media');
    }

    public function test_a_stranger_to_the_attached_model_is_still_refused(): void
    {
        $spy = $this->spyIngestor();
        $owner = IngestOpOwner::create(['editor_ids' => $this->member()->id]);
        $media = $this->media(IngestOpOwner::class, $owner->id);

        $this->actingAs($this->member())
            ->postJson("/media/{$media->uuid}/ingest")
            ->assertForbidden();

        $this->assertSame(0, $spy->calls, 'delegating to an owner must not admit a non-owner');
    }

    /**
     * A media row with no resolvable owner must DENY, and must not raise. `MorphTo` instantiates an
     * unmapped `model_type` string as a class name, so the unguarded read is an `Error` on a security
     * path — a 500 where the honest answer is 403.
     */
    #[DataProvider('ownerlessRows')]
    public function test_an_unresolvable_owner_denies_rather_than_crashing_or_allowing(string $type, int $id): void
    {
        $spy = $this->spyIngestor();
        $media = $this->media($type, $id);

        $this->actingAs($this->member())
            ->postJson("/media/{$media->uuid}/ingest")
            ->assertForbidden();

        $this->assertSame(0, $spy->calls);
    }

    /**
     * ⚠️ There is no "empty morph" row in this list, and that is a measurement rather than an omission:
     * `create_media_table` declares `uuidMorphs('model')`, which is NOT NULL on both columns, so a media
     * row with no owner at all cannot be persisted (SQLite refuses it outright — measured 2026-08-31).
     * The gate still guards the null branch, because a host that adopted a table with nullable morph
     * columns is a shape this package's own migration explicitly handles.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function ownerlessRows(): array
    {
        return [
            'a morph type absent from the map, so the class does not exist' => ['probe', 1],
            'a resolvable type pointing at a row that is gone' => [IngestOpOwner::class, 99999],
        ];
    }

    // ── The forged polymorphic owner (api-surface-coherence 65's shape, re-probed on THIS path) ──

    /**
     * ⚠️ Delegating authorization to `$media->model` is only sound if that pair cannot be chosen by the
     * caller — a policy that trusts a forgeable owner is worse than no policy. `api-surface-coherence`
     * 65 was exactly this forgery on exactly this model.
     *
     * It is not forgeable on the ingest path, and this asserts the strongest form of that: the actor
     * sends a `model_type`/`model_id` naming a model they genuinely CAN update, on a media row owned by
     * something else. If the body reached the gate, this would be a 200.
     *
     * The structural reason is that `IngestMedia` never writes: the op's subject is resolved from the
     * route `{id}` through the resource backing, and `handle()` hands the request to the ingestor
     * without touching a column. The body is inert here by construction, not by a strip.
     */
    public function test_a_body_supplied_owner_pair_cannot_redirect_the_gate(): void
    {
        $spy = $this->spyIngestor();
        $mine = IngestOpOwner::create(['editor_ids' => ($actor = $this->member())->id]);
        $theirs = IngestOpOwner::create(['editor_ids' => $this->member()->id]);

        // Control: the forged pair names something this actor really can update, so a body that
        // reached the gate would admit them.
        $this->assertTrue(Gate::forUser($actor)->allows('update', $mine));

        $media = $this->media(IngestOpOwner::class, $theirs->id);

        $this->actingAs($actor)
            ->postJson("/media/{$media->uuid}/ingest", [
                'model_type' => IngestOpOwner::class,
                'model_id' => $mine->id,
            ])
            ->assertForbidden();

        $this->assertSame(0, $spy->calls);

        // …and the row is unchanged, so the forgery did not land as a write either.
        $this->assertSame((string) $theirs->id, (string) $media->fresh()->model_id);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────

    private function media(string $modelType = 'probe', int $modelId = 1): Media
    {
        return Media::create([
            'model_type' => $modelType,
            'model_id' => $modelId,
            'collection_name' => 'default',
            'name' => 'probe',
            'file_name' => 'probe.txt',
            'disk' => 'public',
            'size' => 1,
            'manipulations' => [],
            'custom_properties' => [],
            'generated_conversions' => [],
            'responsive_images' => [],
        ]);
    }

    private function member(): IngestOpUser
    {
        return IngestOpUser::create(['email' => 'm'.mt_rand().'@beam.test']);
    }

    private function memberHolding(string $ability): IngestOpUser
    {
        $user = $this->member();
        $user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function spyIngestor(): SpyMediaIngestor
    {
        $spy = new SpyMediaIngestor;
        $this->app->instance(MediaIngestor::class, $spy);

        return $spy;
    }

    private function createSpatiePermissionSchema(): void
    {
        Schema::create('permissions', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('guard_name');
            $t->timestamps();
            $t->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('team_id')->nullable();
            $t->string('name');
            $t->string('guard_name');
            $t->timestamps();
        });

        Schema::create('model_has_permissions', function (Blueprint $t): void {
            $t->unsignedBigInteger('permission_id');
            $t->string('model_type');
            $t->unsignedBigInteger('model_id');
            $t->unsignedBigInteger('team_id')->nullable();
            $t->index(['model_id', 'model_type']);
        });

        Schema::create('model_has_roles', function (Blueprint $t): void {
            $t->unsignedBigInteger('role_id');
            $t->string('model_type');
            $t->unsignedBigInteger('model_id');
            $t->unsignedBigInteger('team_id')->nullable();
            $t->index(['model_id', 'model_type']);
        });

        Schema::create('role_has_permissions', function (Blueprint $t): void {
            $t->unsignedBigInteger('permission_id');
            $t->unsignedBigInteger('role_id');
        });
    }
}
