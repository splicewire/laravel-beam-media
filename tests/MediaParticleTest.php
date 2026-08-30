<?php

namespace Splicewire\Beam\Media\Tests;

use Illuminate\Http\Request;
use ReflectionClass;
use Splicewire\Beam\Media\Contracts\MediaIngestor;
use Splicewire\Beam\Media\Data\MediaData;
use Splicewire\Beam\Media\Data\MediaWriteInputData;
use Splicewire\Beam\Media\Models\Media;
use Splicewire\Beam\Media\Ops\DownloadMedia;
use Splicewire\Beam\Media\Ops\IngestMedia;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Particle\Delivery\DeliveryResolvers;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * beam-media's own particle-declaration contract (HTTP-10). Unit-level: the attribute wiring + the
 * projection wire shape, all Fragment-agnostic (this package never imports Tower's Fragment). The full
 * CRUD/route/relative-mount integration is exercised host-side in the app suite (test_http10).
 */
class MediaParticleTest extends TestCase
{
    public function test_media_data_declares_the_media_particle_resource(): void
    {
        $attr = (new ReflectionClass(MediaData::class))->getAttributes(ParticleResource::class);

        $this->assertNotEmpty($attr, 'MediaData must carry #[ParticleResource].');

        $resource = $attr[0]->newInstance();
        $this->assertSame('media', $resource->key);
        // `model:` became `backing:` in the particle-contribution-seam ticket-13 rename (d7085af); this
        // assertion was left naming the old slot and had been erroring ever since (the 43/48/53/55/59
        // stale-assertion class, cause: api-surface-coherence 81 — no package suite runs unattended).
        $this->assertSame(Media::class, $resource->backing);

        // api-surface-coherence 65: the write body is DECLARED. `input: null` does not mean "no body" on
        // the REST axis — `ParticleController::parseInput()` passes the raw Request through and every key
        // snake-maps onto a `$guarded = []` model, so it meant "any body" and the polymorphic owner was
        // forgeable. The declaration is the fix; see MediaWriteInputData for what it deliberately omits.
        $this->assertSame(MediaWriteInputData::class, $resource->input);
        // filterable:false — the relative-mount index must scope THROUGH $fragment->media(); a
        // filterable index rides the data-filters builder and bypasses the bound relative. See MediaData.
        $this->assertFalse($resource->filterable);
        $this->assertSame(20, $resource->perPage);
    }

    public function test_project_emits_the_media_ref_wire_subset(): void
    {
        $media = new Media;
        $media->uuid = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';
        $media->file_name = 'verse-guide.mid';
        $media->mime_type = 'audio/midi';
        $media->size = 2048;
        // original_url is a spatie appended accessor over disk+path; force it via the attribute bag so the
        // projection reads a deterministic value without touching the filesystem.
        $media->setAttribute('disk', 'public');
        $media->setAttribute('id', 1);

        $data = MediaData::project($media);

        $this->assertSame('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', $data->uuid);
        $this->assertSame('verse-guide.mid', $data->fileName);
        $this->assertSame('audio/midi', $data->mimeType);
        $this->assertSame(2048, $data->size);
        // content_url mirrors original_url (fork ③ — the wire field IS the storage URL).
        $this->assertSame($media->original_url, $data->contentUrl);
        $this->assertSame($media->original_url, $data->originalUrl);
    }

    public function test_wire_shape_is_snake_cased_with_the_media_ref_keys(): void
    {
        $data = new MediaData(
            uuid: '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
            fileName: 'verse-guide.mid',
            contentUrl: 'https://tenant.app.splicewire.test/media/1/verse-guide.mid',
            originalUrl: 'https://tenant.app.splicewire.test/media/1/verse-guide.mid',
            mimeType: 'audio/midi',
            size: 2048,
        );

        $wire = $data->toArray();

        // The exact contract subset (acceptance #1): snake_case wire names, regardless of host config.
        $this->assertSame('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', $wire['uuid']);
        $this->assertSame('verse-guide.mid', $wire['file_name']);
        $this->assertSame('https://tenant.app.splicewire.test/media/1/verse-guide.mid', $wire['content_url']);
        $this->assertSame('audio/midi', $wire['mime_type']);
        $this->assertSame(2048, $wire['size']);
        // original_url carried too, so the MediaRef `original_url`-first parse is a no-op (dual-name #2).
        $this->assertSame('https://tenant.app.splicewire.test/media/1/verse-guide.mid', $wire['original_url']);
    }

    public function test_an_absent_order_column_is_untouched_and_an_explicit_null_clears_it(): void
    {
        // The three input states a PATCH has to keep apart. `order_column` is the one nullable column on
        // this DTO whose null is a legitimate persisted state (spatie treats it as "unordered"), so
        // "remove this record's explicit position" has to be expressible — and on the `!== null` gate it
        // was not: an omitted field and an explicit null both arrived as null and both were skipped.
        $absent = MediaWriteInputData::from(['name' => 'clip'])->toModelAttributes();
        $this->assertArrayNotHasKey('order_column', $absent, 'An omitted field must not be written.');

        $cleared = MediaWriteInputData::from(['name' => 'clip', 'orderColumn' => null])->toModelAttributes();
        $this->assertArrayHasKey('order_column', $cleared, 'An explicit null must reach the column.');
        $this->assertNull($cleared['order_column']);

        $set = MediaWriteInputData::from(['orderColumn' => 3])->toModelAttributes();
        $this->assertSame(3, $set['order_column']);
    }

    public function test_the_not_null_columns_stay_on_the_drop_nulls_gate(): void
    {
        // Deliberate non-conversion: `name`, `collection_name`, `file_name`, `size` and `custom_properties`
        // are all NOT NULL in `create_media_table`, so a "clear" on any of them is a constraint violation
        // dressed up as an API affordance. They keep the `!== null` gate, where an explicit null is a
        // harmless no-op. `mime_type` IS nullable and is still held back — see the class docblock.
        $attributes = MediaWriteInputData::from([
            'name' => null,
            'collectionName' => null,
            'fileName' => null,
            'size' => null,
            'customProperties' => null,
            'mimeType' => null,
        ])->toModelAttributes();

        $this->assertSame([], $attributes);
    }

    public function test_download_is_a_read_particle_op(): void
    {
        $attr = (new ReflectionClass(DownloadMedia::class))->getAttributes(ParticleOp::class);
        $this->assertNotEmpty($attr, 'DownloadMedia must carry #[ParticleOp].');

        $op = $attr[0]->newInstance();
        $this->assertSame('media', $op->resource);
        $this->assertSame('download', $op->name);
        $this->assertSame(OperationKind::Read, $op->kind);
        $this->assertSame(Media::class, $op->model);
        $this->assertTrue(method_exists(DownloadMedia::class, 'handle'));
    }

    public function test_download_declares_what_it_puts_on_the_wire(): void
    {
        // particle-operation-surface 14, acceptance #6. Asserted through the RUNTIME operation and not
        // off the attribute alone: a slot that reaches the runtime object as its default is
        // indistinguishable from one that was never declared, which is exactly how `signed:` stayed
        // unforwarded for two days without a failing test.
        (new AttributedParticleDiscovery(
            $this->app->make(ParticleResourceRegistry::class),
            $this->app->make(ParticleOperationRegistry::class),
        ))->registerClass(DownloadMedia::class);

        $operation = $this->app->make(ParticleOperationRegistry::class)->get('media', 'download');

        $contract = DeliveryResolvers::contract($operation);

        $this->assertSame(['application/octet-stream'], $contract['mediaTypes']);
        $this->assertArrayHasKey('Content-Disposition', $contract['headers']);

        // Empty is the STRONGER statement: one representation, no `?format` knob ever read. It also
        // keeps `format` out of `frameworkParameters()`, which this op needs — it declares
        // `input: false`, and a format axis would put the parameter on a collision course with
        // `rejectInput()`.
        $this->assertSame([], $contract['formats']);
        $this->assertSame([], $operation->frameworkParameters());
    }

    public function test_ingest_is_a_write_particle_op(): void
    {
        $attr = (new ReflectionClass(IngestMedia::class))->getAttributes(ParticleOp::class);
        $this->assertNotEmpty($attr, 'IngestMedia must carry #[ParticleOp].');

        $op = $attr[0]->newInstance();
        $this->assertSame('media', $op->resource);
        $this->assertSame('ingest', $op->name);
        $this->assertSame(OperationKind::Write, $op->kind);
        $this->assertSame(Media::class, $op->model);
        $this->assertTrue(method_exists(IngestMedia::class, 'handle'));
    }

    public function test_ingest_op_is_a_noop_when_no_host_ingestor_is_bound(): void
    {
        // Fragment-agnostic contract (HTTP-12): a bare beam-media install has no pipeline; the op returns
        // the media unchanged rather than erroring.
        $this->assertFalse($this->app->bound(MediaIngestor::class));

        $media = new Media;
        $result = IngestMedia::handle($media, Request::create('/media/x/op/ingest', 'POST'), null);

        $this->assertSame($media, $result);
    }

    public function test_ingest_op_delegates_to_the_host_bound_ingestor(): void
    {
        // When a host binds the MediaIngestor port (Tower's TowerMediaIngestor), the op delegates to it —
        // beam-media owns the generic OPERATION; the host owns the pipeline. Input is the request.
        $media = new Media;

        $ingestor = new class implements MediaIngestor
        {
            public bool $called = false;

            public function ingest(Media $media, Request $request): Media
            {
                $this->called = true;

                return $media;
            }
        };
        $this->app->instance(MediaIngestor::class, $ingestor);

        $request = Request::create('/media/x/op/ingest', 'POST', ['also_ingest' => true]);
        $result = IngestMedia::handle($media, $request, null);

        $this->assertTrue($ingestor->called, 'IngestMedia must delegate to the bound MediaIngestor.');
        $this->assertSame($media, $result);
    }
}
