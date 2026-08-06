<?php

namespace Splicewire\Beam\Media\Tests;

use ReflectionClass;
use Splicewire\Beam\Media\Data\MediaData;
use Splicewire\Beam\Media\Models\Media;
use Splicewire\Beam\Media\Ops\DownloadMedia;
use Splicewire\Beam\Media\Ops\IngestMedia;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Particle\OperationKind;

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
        $this->assertSame(Media::class, $resource->model);
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
}
