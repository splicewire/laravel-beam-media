# Media API root

`MediaRoutes::mount()` exports the existing show, update and destroy actions, download operation, scoped hook-event catalog and runtime discovery at `beam.media.api_root` (default `beam/media`). It adds no index or store route. `MediaRoutes::ingest()` is a separate opt-in and retains `throttle:beam-media.ingest` and the operation's declared ability.

The host supplies authentication and tenancy. Parent-relative media remains at its parent resource; Tower's metered analysis operations remain in Tower. Keep legacy mounts until ticket 16; the flagship and satellite adoption belongs to tickets 14a and 15. Names use the `beam.media.*` namespace (the mounter also includes the resource key), so they stay distinct from legacy names during expansion.

This package uses Beam ApiRoot (ticket 12, landed c583da31). The landing owner resolves that prerequisite; there is no new provider auto-mount. Reserve tier paths in host policies and mount before site catch-alls. The own-source census and exact host route parity are filed in owner-review 13e READY.
