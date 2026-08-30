<?php

namespace Splicewire\Beam\Media\Delivery;

use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Media\Ops\DownloadMedia;
use Splicewire\Beam\Rendering\DeclaresDelivery;

/**
 * What {@see DownloadMedia} puts on the wire (particle-operation-surface 14, acceptance #6).
 *
 * The op streams a stored file back through Spatie's `Media::toResponse()`, which sends the record's
 * own mime type and an `attachment` disposition. Before the `delivery:` slot existed there was nowhere
 * to say any of that: `output:` names a Data class and answers *what JSON shape comes back*, which is
 * structurally the wrong question for an endpoint that returns bytes — so `media.download` published an
 * **untyped 200 with no media type at all**, and a generated client had no way to know it was handed a
 * file rather than an envelope.
 *
 * ## Why `application/octet-stream` and not the record's real type
 *
 * The concrete type is a fact about ONE row — `image/png` for this media, `application/pdf` for that
 * one — and a spec is written at build time against no record. Naming any single concrete type would be
 * a lie about every other row; enumerating the estate's mime column would be a lie about the next
 * upload. `application/octet-stream` is the honest statement this endpoint can make at build time:
 * *arbitrary bytes, delivered as an attachment*. The per-request truth is on the response's own
 * `Content-Type`, which is the transport's and is where a client should read it.
 *
 * ## No format axis, deliberately
 *
 * `formats()` is empty, which is a stronger statement than a one-member list: this operation has one
 * representation and has never read a `?format`. That keeps
 * {@see ParticleOperationController::format()} silent for it, publishes
 * no `format` parameter, and writes no 422 — the endpoint documents and behaves exactly as it did,
 * gaining only the media type and the two headers it genuinely sets. ⚠️ It also keeps `format` out of
 * `frameworkParameters()`, which matters here more than anywhere: `DownloadMedia` declares
 * `input: false`, so a format axis would put `?format` on a collision course with `rejectInput()` —
 * the shape api-surface-coherence 95 was bitten by on `expires`/`signature`.
 */
class MediaFileDelivery implements DeclaresDelivery
{
    public function mediaTypes(): array
    {
        return ['application/octet-stream'];
    }

    public function deliveryHeaders(): array
    {
        return [
            'Content-Disposition' => 'Always `attachment`, with the stored file name — this endpoint '
                .'hands back a file to save, never a body to render inline.',
            'Content-Length' => 'The stored file size in bytes.',
        ];
    }

    public function defaultFormat(): ?string
    {
        return null;
    }

    public function formats(): array
    {
        return [];
    }
}
