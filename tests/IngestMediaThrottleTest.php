<?php

namespace Splicewire\Beam\Media\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Media\BeamMediaServiceProvider;
use Splicewire\Beam\Media\Contracts\MediaIngestor;
use Splicewire\Beam\Media\Data\MediaData;
use Splicewire\Beam\Media\Models\Media;
use Splicewire\Beam\Media\Ops\IngestMedia;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * particle-write-surface ticket 02, second half — the COST control on `media.ingest`.
 *
 * The gate answers "may you"; it cannot answer "how often", and ingest is unbounded and repeatable:
 * the same media row can be ingested any number of times and each pass runs the host-bound pipeline
 * (embeddings, graph triples, silo filing at the flagship). So the bound is the named limiter
 * `beam-media.ingest` that {@see BeamMediaServiceProvider} registers, attached
 * by the host at the mount.
 *
 * ## The limiter has TWO axes and neither subsumes the other
 *
 * Both are asserted here as separate findings, because they bound different risks:
 *
 *   - **per actor** is FAIRNESS. Without it, one member consumes the whole tenant allowance.
 *   - **per tenant** is BUDGET. Without it, the intended ceiling is multiplied by the tenant's member
 *     count — the tenant pays for the tokens, so the tenant is the unit the money bound is written in.
 *
 * ## ⚠️ Gate posture and throttle posture, both stated
 *
 * **No `Gate::before(fn () => true)`, no policy on {@see Media}.** The actor is admitted by the fine
 * gate — they own the model the media hangs off — so every 429 below is a request that WOULD have been
 * a 200, which is the only way a throttle assertion means anything. A 429 on an already-forbidden
 * request proves nothing.
 *
 * **The throttle is mounted by this test, not ambient.** The first test is the control: the same
 * requests against a mount with no throttle middleware all answer 200, so a 429 elsewhere is the
 * limiter and not something in the harness.
 *
 * ## Why no suite in this estate can be silently rate-limited by this
 *
 * The limiter is REGISTERED by the package and ATTACHED by the host, so an unmounted throttle is
 * inert. Beyond that, `null` on either config key disables that axis and both null yields
 * `Limit::none()` — asserted below, so the escape hatch a test environment needs is a tested
 * behaviour rather than a claim. Every test here sets its own numbers explicitly and mounts its own
 * routes, so nothing depends on the shipped defaults and nothing carries state between tests (the
 * limiter counts in the array cache store, which testbench rebuilds per test).
 */
class IngestMediaThrottleTest extends TestCase
{
    /** The stand-in tenancy binding {@see BeamMediaServiceProvider} probes. */
    private const TENANT_CONTRACT = 'Stancl\Tenancy\Contracts\Tenant';

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

        app(ParticleResourceRegistry::class)->registerClass(MediaData::class);

        // The delegation target's own rule — the flagship's is `Fragment`'s `#[UseCascadePolicy]`.
        // Deliberately two-sided (see `IngestMediaAuthorizationTest`, which asserts both directions).
        Gate::policy(IngestOpOwner::class, IngestOpOwnerPolicy::class);
    }

    // ── Controls ────────────────────────────────────────────────────────────────────────────────

    public function test_control_an_unthrottled_mount_admits_the_owner_indefinitely(): void
    {
        $this->limits(perActor: 1, perTenant: 1);
        $this->mount(throttle: false);

        [$actor, $media] = $this->ownedMedia();
        $spy = $this->spyIngestor();

        foreach (range(1, 5) as $ignored) {
            $this->actingAs($actor)->postJson("/media/{$media->uuid}/op/ingest")->assertOk();
        }

        $this->assertSame(5, $spy->calls, 'limits configured but not attached ⇒ nothing is bounded');
    }

    // ── The actor axis ──────────────────────────────────────────────────────────────────────────

    public function test_the_actor_axis_bounds_repeat_ingests_and_the_pipeline_stops_running(): void
    {
        $this->limits(perActor: 2, perTenant: null);
        $this->mount();

        [$actor, $media] = $this->ownedMedia();
        $spy = $this->spyIngestor();

        $this->actingAs($actor)->postJson("/media/{$media->uuid}/op/ingest")->assertOk();
        $this->actingAs($actor)->postJson("/media/{$media->uuid}/op/ingest")->assertOk();
        $this->actingAs($actor)->postJson("/media/{$media->uuid}/op/ingest")->assertStatus(429);

        // Observed as "the pipeline did not run", not read off the status — a 429 that still spent
        // tokens would be a cost control that costs.
        $this->assertSame(2, $spy->calls);
    }

    public function test_one_actors_exhausted_bucket_does_not_deny_their_colleague(): void
    {
        $this->limits(perActor: 1, perTenant: null);
        $this->mount();

        [$actor, $media] = $this->ownedMedia();
        $colleague = $this->colleagueOn($media);
        $spy = $this->spyIngestor();

        $this->actingAs($actor)->postJson("/media/{$media->uuid}/op/ingest")->assertOk();
        $this->actingAs($actor)->postJson("/media/{$media->uuid}/op/ingest")->assertStatus(429);

        // The finding: the actor axis is genuinely per-actor. If it were keyed on the media row or the
        // route, this would be a 429 and the fairness bound would be a denial-of-service on colleagues.
        $this->actingAs($colleague)->postJson("/media/{$media->uuid}/op/ingest")->assertOk();

        $this->assertSame(2, $spy->calls);
    }

    // ── The tenant axis ─────────────────────────────────────────────────────────────────────────

    public function test_the_tenant_axis_bounds_the_whole_tenant_across_distinct_actors(): void
    {
        $this->limits(perActor: null, perTenant: 2);
        $this->app->instance(self::TENANT_CONTRACT, new FakeTenant('acme'));
        $this->mount();

        [$first, $media] = $this->ownedMedia();
        $spy = $this->spyIngestor();

        $this->actingAs($first)->postJson("/media/{$media->uuid}/op/ingest")->assertOk();
        $this->actingAs($this->colleagueOn($media))->postJson("/media/{$media->uuid}/op/ingest")->assertOk();

        // A THIRD, entirely fresh actor — with the per-actor axis disabled, the only thing that can
        // refuse them is the tenant's shared budget. This is the assertion a per-actor-only throttle
        // fails, and it is the one the money depends on.
        $this->actingAs($this->colleagueOn($media))->postJson("/media/{$media->uuid}/op/ingest")->assertStatus(429);

        $this->assertSame(2, $spy->calls);
    }

    public function test_two_tenants_do_not_share_a_bucket(): void
    {
        $this->limits(perActor: null, perTenant: 1);
        $this->app->instance(self::TENANT_CONTRACT, new FakeTenant('acme'));
        $this->mount();

        [$actor, $media] = $this->ownedMedia();
        $spy = $this->spyIngestor();

        $this->actingAs($actor)->postJson("/media/{$media->uuid}/op/ingest")->assertOk();
        $this->actingAs($actor)->postJson("/media/{$media->uuid}/op/ingest")->assertStatus(429);

        $this->app->instance(self::TENANT_CONTRACT, new FakeTenant('globex'));

        $this->actingAs($actor)->postJson("/media/{$media->uuid}/op/ingest")->assertOk();

        $this->assertSame(2, $spy->calls);
    }

    // ── The escape hatch, which is what keeps suites from going flaky ───────────────────────────

    public function test_null_limits_disable_the_limiter_entirely(): void
    {
        $this->limits(perActor: null, perTenant: null);
        $this->mount();

        [$actor, $media] = $this->ownedMedia();
        $spy = $this->spyIngestor();

        foreach (range(1, 10) as $ignored) {
            $this->actingAs($actor)->postJson("/media/{$media->uuid}/op/ingest")->assertOk();
        }

        $this->assertSame(10, $spy->calls, 'both axes null ⇒ Limit::none(), so a test env cannot be throttled');
    }

    /** A revert of the config defaults to something unbounded fails here. */
    public function test_the_shipped_defaults_bound_both_axes(): void
    {
        $defaults = require __DIR__.'/../config/beam/media.php';

        $this->assertIsInt($defaults['ingest']['throttle']['per_actor']);
        $this->assertIsInt($defaults['ingest']['throttle']['per_tenant']);
        $this->assertGreaterThan(
            $defaults['ingest']['throttle']['per_actor'],
            $defaults['ingest']['throttle']['per_tenant'],
            'the tenant ceiling must exceed one member’s, or the fairness axis is the only live one'
        );
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────

    private function limits(?int $perActor, ?int $perTenant): void
    {
        config([
            'beam.media.ingest.throttle.per_actor' => $perActor,
            'beam.media.ingest.throttle.per_tenant' => $perTenant,
        ]);
    }

    /**
     * Mount the real op with beam's own mounter, optionally inside the throttle group the host applies.
     * This IS the documented host mount — nothing here restates the declaration.
     */
    private function mount(bool $throttle = true): void
    {
        $mount = fn () => Particle::ops('media', 'media', [IngestMedia::class]);

        $throttle
            ? Route::middleware('throttle:beam-media.ingest')->group($mount)
            : $mount();
    }

    /** @return array{0: IngestOpUser, 1: Media} */
    private function ownedMedia(): array
    {
        $actor = IngestOpUser::create(['email' => 'a'.mt_rand().'@beam.test']);
        $owner = IngestOpOwner::create(['editor_ids' => $actor->id]);

        $media = Media::create([
            'model_type' => IngestOpOwner::class,
            'model_id' => $owner->id,
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

        return [$actor, $media];
    }

    /**
     * A second (third, fourth…) user who can ALSO update the media's owner — a colleague, not a
     * stranger. Added to the editor list rather than replacing it, so every actor minted this way stays
     * admitted for the rest of the test; a fixture that moved authorization instead would be measuring
     * the gate again where the point is to measure the throttle.
     */
    private function colleagueOn(Media $media): IngestOpUser
    {
        $user = IngestOpUser::create(['email' => 'c'.mt_rand().'@beam.test']);

        IngestOpOwner::whereKey($media->model_id)->firstOrFail()->addEditor($user->id);

        return $user;
    }

    private function spyIngestor(): SpyMediaIngestor
    {
        $spy = new SpyMediaIngestor;
        $this->app->instance(MediaIngestor::class, $spy);

        return $spy;
    }
}

/** The narrowest thing satisfying the container probe: a tenant is a key, for this purpose. */
class FakeTenant
{
    public function __construct(private string $key) {}

    public function getTenantKey(): string
    {
        return $this->key;
    }
}
