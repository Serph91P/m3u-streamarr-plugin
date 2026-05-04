# Changelog

## v1.11.0 - 2026-05-04
### Added
- Auto-migration: legacy `monitored_channels` setting is split into the per-platform fields on first run (idempotent, marked via `__streamarr_legacy_migrated`).
- Central per-platform channel resolver `getChannelsForPlatform()`.
- Central per-platform group resolver `resolveGroupForPlatform()` with chain: `{platform}_group` -> `channel_group` -> provider default.
- Kick streams now have their own check loop and use `kick_group` for grouping.

### Changed
- `handleCheckNow()` no longer concatenates all channel fields into one source. Each platform is parsed independently from its own settings field.
