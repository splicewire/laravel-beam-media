<?php

namespace Splicewire\Beam\Media\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;
use Splicewire\Beam\Facades\Particle;
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
 * ⚠️ **The cost half of that finding is deliberately NOT tested here, because it is not this gate's
 * job.** An ability answers "may you", never "how often". Bounding repeat ingests is a throttle on the
 * mount or a quota in the ingestor; see the declaration's own comment.
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

        $this->createSpatiePermissionSchema();

        app(ParticleResourceRegistry::class)->registerClass(MediaData::class);

        // The op is host-mounted in the real estate (`splicewire-app`'s `routes/tenant.php`), so this
        // harness mounts the REAL class with beam's own mounter. The declaration under test is the one
        // on `IngestMedia`; nothing here restates it.
        Particle::ops('media', 'media', [IngestMedia::class]);

        // GATE CLOSED: note what is absent — no `Gate::policy(Media::class, …)`, no
        // `Gate::before(fn () => true)`. Nothing in this app can allow anything against a Media except
        // a permission row.
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
            ->postJson("/media/{$media->uuid}/op/ingest")
            ->assertForbidden();

        $this->assertSame(0, $spy->calls, 'refused ⇒ no tokens spent, no silo membership mutated');
    }

    public function test_the_permission_holder_is_admitted_and_the_ingest_pipeline_runs(): void
    {
        $spy = $this->spyIngestor();
        $media = $this->media();

        $this->actingAs($this->memberHolding('media.ingest'))
            ->postJson("/media/{$media->uuid}/op/ingest")
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

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────

    private function media(): Media
    {
        return Media::create([
            'model_type' => 'probe',
            'model_id' => 1,
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

/** Counts pipeline runs, so "refused" is observed as "the ingestor never ran", not read off a status. */
class SpyMediaIngestor implements MediaIngestor
{
    public int $calls = 0;

    public function ingest(Media $media, Request $request): Media
    {
        $this->calls++;

        return $media;
    }
}

class IngestOpUser extends AuthUser
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}
