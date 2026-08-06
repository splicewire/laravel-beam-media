# splicewire/laravel-beam-media

The **media arm** of the beam family. Extracted from beam-core (HTTP-03 / ADR-0178).

Owns:
- `Models\Media` — the UUID-native base Media model (`extends Spatie\MediaLibrary\...\Media`, `uuid` primary key, `keyType=string`/`incrementing=false`). A host subclasses it and binds via `config('beam-media.model')`.
- `Concerns\HasPrimaryImages` + `Concerns\HasFeaturedImage` — the declarative primary-image traits over spatie/laravel-medialibrary.
- The ubiquitous `media` table migrations (central + tenant) — publish-only.
- **`Data\MediaData`** — the generic Media **particle** (`#[ParticleResource]`), the first attribute-declared particle in the estate.
- **`Ops\DownloadMedia`** (`#[ParticleOp(kind: Read)]`) + **`Ops\IngestMedia`** (`#[ParticleOp(kind: Write)]`).
- `spatie/laravel-medialibrary` + `splicewire/laravel-beam` (particle machinery) as DOWN dependencies.

## The Media particle (HTTP-10 / ADR-0178)

Media is a **fully-generic, attribute-declared particle** — no bespoke controller. `Data\MediaData` carries
`#[ParticleResource(key:'media', model: Media::class, filterable:false, perPage:20)]` and a convention
`project(Media): self`, emitting `{ uuid, file_name, content_url, mime_type, size }` (plus `original_url`,
so the spine/client `MediaRef` `original_url`-first parse is a no-op — the wire contract holds with MediaRef
unchanged). `content_url` **is** Spatie's `original_url` (the vestigial `content_url` route accessor + the
`media.content` route were dropped, fork ③).

- **`filterable: false`** (deviates from the 10-spec's `true`, a deliberate correction): beam-core's
  `ParticleController::index` rides the data-filters builder when `filterable`, which **bypasses** a bound
  relative — so a `filterable: true` index would break the fragment-relative mount's scoping (it would list
  every media row, not the fragment's). `false` is the only setting under which the relative index scopes
  through `$fragment->media()`. Media has no facet filters, so nothing is lost.
- **`download`** (`Ops\DownloadMedia`, `kind: Read`) is the FIRST real `#[ParticleOp]` consumer — streams
  Spatie's `StreamedResponse` through untouched. **`ingest`** (`Ops\IngestMedia`, `kind: Write`) is the
  upload side-effect op; its input DTO is ticket 12's upload InputData (a placeholder no-op today).

### Mounting (host-owned — the Fragment coupling stays in the host)

beam-media is **Fragment-agnostic**: it never imports Tower's `Fragment`. A host mounts the particle:

```php
// standalone — /media, /media/{uuid} by bare uuid (the melody_ref cell reference)
Route::particleResource('media', 'media', ['idConstraint' => 'uuid']);
Route::particleOps('media', 'media', [DownloadMedia::class], ['method' => 'get', 'idConstraint' => 'uuid']);
Route::particleOps('media', 'media', [IngestMedia::class], ['idConstraint' => 'uuid']);

// fragment-relative (Tower host) — POST /fragments/{fragment}/media creates through $fragment->media()
Route::particleRelative('fragments', Fragment::class, via: 'media', routes: function () {
    Route::particleResource('media', 'media', ['only' => ['index', 'store'], 'idConstraint' => 'uuid']);
}, options: ['binding' => 'fragment']);
```

Register the attribute resource at boot: `app(AttributedParticleDiscovery::class)->discover([MediaData::class])`.

## Topology

beam-media depends **DOWN** on `spatie/laravel-medialibrary` and — as a **particle consumer** (the
`MediaData` `#[ParticleResource]` + the ops) — on base `splicewire/laravel-beam`, exactly as the sibling arms
(accounts / bookmarks / taxonomy) do. The ADR-0178 invariant is **one-directional**: base beam does **NOT**
require beam-media (no reverse edge → **no cycle**). A host that wants media requires beam-media directly.

## Host binding

`config('beam-media.model')` is the media-model seam. The service provider feeds it into spatie's `media-library.media_model` unless the host has bound its own. Tower overrides it with its `Media` subclass (source-meta scopes, flags/tags, content-url accessor) and keeps its `TenantAwarePathGenerator`.
