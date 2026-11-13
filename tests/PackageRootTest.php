<?php

namespace Splicewire\Beam\Media\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Media\Ops\DownloadMedia;
use Splicewire\Beam\Media\Routing\MediaRoutes;

class PackageRootTest extends TestCase
{
    public function test_default_and_override_preserve_the_host_surface_and_keep_ingest_opt_in(): void
    {
        $this->app->register(LaravelDataSchemasServiceProvider::class);
        Route::prefix('legacy')->name('legacy.')->middleware('host')->group(function (): void {
            Particle::mount('media')->only(['show', 'update', 'destroy'])->idConstraint('uuid');
            Particle::ops('media', 'media', [DownloadMedia::class]);
        });
        Route::middleware('host')->group(fn () => MediaRoutes::mount());
        $routes = app('router')->getRoutes();
        $rows = collect($routes->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'beam/media'));
        $this->assertCount(5, $rows);
        foreach ($rows as $new) {
            $suffix = substr($new->uri(), strlen('beam/'));
            $old = collect($routes->getRoutes())->first(fn ($r) => $r->uri() === 'legacy/'.$suffix && $r->methods() === $new->methods());
            $this->assertNotNull($old);
            $this->assertSame($old->getActionName(), $new->getActionName());
            $this->assertSame($old->gatherMiddleware(), $new->gatherMiddleware());
            $this->assertSame($old->wheres, $new->wheres);
            $this->assertSame($old->defaults, $new->defaults);
        }
        $this->assertFalse(collect($routes->getRoutes())->contains(fn ($r) => str_ends_with($r->uri(), '/ingest')));
        MediaRoutes::ingest();
        $id = '10f8c924-a216-4450-90f3-601a33f23c79';
        $this->assertContains('throttle:beam-media.ingest', $routes->match(Request::create('/beam/media/'.$id.'/ingest', 'POST'))->gatherMiddleware());
        config(['beam.media.api_root' => 'custom/files']);
        MediaRoutes::mount();
        $this->assertSame($routes->match(Request::create('/legacy/media/'.$id))->getActionName(), $routes->match(Request::create('/custom/files/'.$id))->getActionName());
    }
}
