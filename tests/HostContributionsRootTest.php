<?php

namespace Splicewire\Beam\Media\Tests;

use Illuminate\Routing\Route as RouteRow;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Media\Routing\MediaRoutes;
use Splicewire\Beam\Media\Tests\Fixtures\HostAnalyzeOp;
use Splicewire\Beam\Media\Tests\Fixtures\HostFolderMediaRelative;
use Splicewire\Beam\Media\Tests\Fixtures\HostSeparationOp;

/**
 * What a host contributes onto `media` twins its legacy rows at the package root (owner-review-20261112 58):
 * ops by name inside the host's own gate (Q61-1), and a host-declared relative edge (Q58-1 (a)).
 */
class HostContributionsRootTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(LaravelDataSchemasServiceProvider::class);
    }

    public function test_host_ops_twin_their_legacy_rows_at_the_media_root_inside_the_host_gate(): void
    {
        Route::prefix('legacy')->name('legacy.')->middleware('host')->group(function (): void {
            Route::middleware('host-medium')->group(fn () => Particle::ops('media', 'media', [HostAnalyzeOp::class, HostSeparationOp::class]));
        });
        Route::middleware('host')->group(fn () => MediaRoutes::ops(['host-analyze', 'host-separation'], ['host-medium']));

        $this->assertTwinParity('beam/media/', 'legacy/media/');
        // Named like the package's own ops (`beam.media.media.download`): the root stem, then the resource's.
        $this->assertSame([
            'GET|HEAD beam/media/{id}/host-separation beam.media.media.host-separation' => ['host', 'host-medium'],
            'POST beam/media/{id}/host-analyze beam.media.media.host-analyze' => ['host', 'host-medium'],
        ], $this->twins());
    }

    public function test_no_named_ops_mount_nothing(): void
    {
        MediaRoutes::ops([], ['host-medium']);
        MediaRoutes::relatives('folders', []);

        $this->assertSame([], $this->twins());
    }

    public function test_a_host_relative_twins_its_legacy_rows_at_the_media_root_and_reclaims_its_binding_quietly(): void
    {
        Log::spy();
        Route::prefix('legacy')->name('legacy.')->middleware('host')->group(fn () => Particle::relatives('folders', [HostFolderMediaRelative::class]));
        Route::middleware('host')->group(fn () => MediaRoutes::relatives('folders', [HostFolderMediaRelative::class]));

        $this->assertTwinParity('beam/media/', 'legacy/');
        // The edge's discovery row is added by the host's boot pass, for the twin as for the legacy row.
        $this->assertSame([
            'GET|HEAD beam/media/folders/{folder}/media beam.media.folders.media.index' => ['host'],
            'GET|HEAD beam/media/folders/{folder}/media/hooks/events beam.media.folders.media.hooks.events' => ['host'],
            'POST beam/media/folders/{folder}/media beam.media.folders.media.store' => ['host'],
        ], $this->twins());
        Log::shouldNotHaveReceived('warning');
    }

    /** Every twin has exactly one legacy row with the same verbs, action, middleware, wheres and defaults. */
    private function assertTwinParity(string $twinPrefix, string $legacyPrefix): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());
        $twins = $routes->filter(fn (RouteRow $r) => str_starts_with($r->uri(), $twinPrefix));
        $this->assertNotEmpty($twins);
        foreach ($twins as $twin) {
            $suffix = substr($twin->uri(), strlen($twinPrefix));
            $legacy = $routes->filter(fn (RouteRow $r) => $r->uri() === $legacyPrefix.$suffix && $r->methods() === $twin->methods());
            $this->assertCount(1, $legacy, 'no single legacy row for '.$twin->uri());
            $old = $legacy->first();
            $this->assertSame($old->getActionName(), $twin->getActionName(), $twin->uri());
            $this->assertSame($old->gatherMiddleware(), $twin->gatherMiddleware(), $twin->uri());
            $this->assertSame($old->wheres, $twin->wheres, $twin->uri());
            $this->assertSame($old->defaults, $twin->defaults, $twin->uri());
            $this->assertSame('legacy.'.substr($twin->getName(), strlen('beam.media.')), $old->getName(), $twin->uri());
        }
    }

    /** @return array<string, list<string>> `METHODS uri name` => middleware, for the rows at the media root */
    private function twins(): array
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (RouteRow $r) => str_starts_with($r->uri(), 'beam/media/'))
            ->mapWithKeys(fn (RouteRow $r) => [implode('|', $r->methods()).' '.$r->uri().' '.$r->getName() => $r->gatherMiddleware()])
            ->sortKeys()
            ->all();
    }
}
