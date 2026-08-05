# splicewire/laravel-beam-media

The **media arm** of the beam family. Extracted from beam-core (HTTP-03 / ADR-0178).

Owns:
- `Models\Media` — the UUID-native base Media model (`extends Spatie\MediaLibrary\...\Media`, `uuid` primary key). A host subclasses it and binds via `config('beam-media.model')`.
- `Concerns\HasPrimaryImages` + `Concerns\HasFeaturedImage` — the declarative primary-image traits over spatie/laravel-medialibrary.
- The ubiquitous `media` table migrations (central + tenant) — publish-only.
- `spatie/laravel-medialibrary` as a DOWN dependency.

## Topology

beam-media depends **DOWN** on `spatie/laravel-medialibrary` only. It does **NOT** require base `splicewire/laravel-beam`, and base beam does **NOT** require beam-media — **no cycle** (verdict B2). A host that wants media requires beam-media directly.

## Host binding

`config('beam-media.model')` is the media-model seam. The service provider feeds it into spatie's `media-library.media_model` unless the host has bound its own. Tower overrides it with its `Media` subclass (source-meta scopes, flags/tags, content-url accessor) and keeps its `TenantAwarePathGenerator`.
