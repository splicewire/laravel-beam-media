<?php

namespace Splicewire\Beam\Media\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Rushing\PermissionCascade\Policies\ConfiguredModelPolicy;
use Rushing\PermissionCascade\Support\PermissionNamer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Splicewire\Beam\Media\Models\Media;

/**
 * api-surface-coherence 147 — `media` was one of the eleven resources whose backing model carried NO
 * policy after 135 bound `Hook`'s. The absence read four ways at once (135): a filters sub-surface falls
 * through, a particle `show`/`destroy` (`authorize('view'|'delete')`) denies everyone but a host's Root
 * bypass, the write pipeline denies, and a Frame nav seat hides. One declaration — `#[UseCascadePolicy]`
 * on the model, bound by THIS package in `packageBooted()` — is consumed by all four.
 *
 * Gate CLOSED: no `Gate::before(fn () => true)` anywhere in this class, the control probe first, and the
 * spatie plane booted so a permission holder is a principal the cascade can admit. The token prefix is
 * `media` — the write-side pin on {@see Media::getMorphClass()}, because a host (tower) owns the
 * read-side `media` alias for its subclass and the base class would otherwise mint the FQCN mush
 * ADR-0118 exists to prevent.
 */
class MediaPolicyGateTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PermissionCascadeServiceProvider::class, PermissionServiceProvider::class];
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
    }

    // ── Controls ────────────────────────────────────────────────────────────────────────────────

    public function test_control_the_gate_is_closed_for_a_stranger(): void
    {
        $this->assertFalse(Gate::forUser($this->member())->allows('probe-nonexistent-ability'));
    }

    public function test_control_the_cascade_provider_is_booted_not_auto_resolved(): void
    {
        // AGENTS.md's testbench trap: an unbooted provider leaves its singleton auto-resolvable and
        // FRESH per call. Identity is the one-line probe that tells the two apart.
        $this->assertSame(app(PermissionNamer::class), app(PermissionNamer::class));
    }

    // ── The declaration, and its four consumers' shared answer ─────────────────────────────────

    public function test_media_binds_a_cascade_policy_that_answers_view_any(): void
    {
        $policy = Gate::getPolicyFor(Media::class);

        $this->assertInstanceOf(ConfiguredModelPolicy::class, $policy);
        $this->assertTrue(method_exists($policy, 'viewAny'));
    }

    public function test_the_token_prefix_is_media_not_the_fqcn(): void
    {
        $this->assertSame('media.view', app(PermissionNamer::class)->assemble(Media::class, 'view'));
    }

    public function test_a_stranger_is_denied_every_ability_on_a_media_row(): void
    {
        $gate = Gate::forUser($this->member());
        $media = $this->media();

        $this->assertFalse($gate->allows('viewAny', Media::class));
        $this->assertFalse($gate->allows('view', $media));
        $this->assertFalse($gate->allows('update', $media));
        $this->assertFalse($gate->allows('delete', $media));
    }

    public function test_a_holder_of_the_class_family_is_admitted_gate_closed(): void
    {
        $holder = $this->memberHolding('media.view', 'media.update');
        $gate = Gate::forUser($holder);
        $media = $this->media();

        $this->assertFalse($gate->allows('probe-nonexistent-ability'));
        $this->assertTrue($gate->allows('viewAny', Media::class));
        $this->assertTrue($gate->allows('view', $media));
        $this->assertTrue($gate->allows('update', $media));
        $this->assertFalse($gate->allows('delete', $media), 'the family is per-ability; update does not imply delete');
    }

    // ── Fixtures ────────────────────────────────────────────────────────────────────────────────

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

    private function memberHolding(string ...$abilities): IngestOpUser
    {
        $user = $this->member();
        foreach ($abilities as $ability) {
            $user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
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
