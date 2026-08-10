> You are in **rushing/laravel-beam-media** — the media arm of the beam family.

The generic UUID-native `Media` model (extends spatie/laravel-medialibrary's base Media with a
uuid primary key), the declarative primary-image traits (`HasPrimaryImages` / `HasFeaturedImage`),
and the ubiquitous media table migrations (central + tenant). Extracted from beam-core: beam-media
owns `spatie/laravel-medialibrary` and requires it down; base beam sheds the medialibrary
dependency and does not require beam-media, and beam-media does not require base beam (no cycle).
A host (Tower) subclasses `Models\Media` and binds it via `config('beam-media.model')`.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
