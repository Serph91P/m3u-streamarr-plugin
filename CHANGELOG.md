# Changelog

## [1.12.0] - 2026-05-04

### Changed
- Internal refactor: streamlink-based platform loops (YouTube, Kick, generic) consolidated into a single provider-driven loop via the new `ProviderRegistry`.
- Added `BaseProvider` abstract class and platform stubs (`TwitchProvider`, `YouTubeProvider`, `KickProvider`, `GenericProvider`) as the foundation for upcoming per-platform detection code (Kick API in 1.13, YouTube API key in 1.14, Tier-2 providers in 1.15).

### Notes
- No user-facing changes. Settings, channel records, and run behaviour are identical to 1.11.0.
- Twitch detection (Helix batch, VODs, EPG) remains inline; provider classes will absorb it incrementally in later phases.

## v1.11.0 - 2026-05-04
### Added
- Auto-migration: legacy `monitored_channels` setting is split into the per-platform fields on first run (idempotent, marked via `__streamarr_legacy_migrated`).
- Central per-platform channel resolver `getChannelsForPlatform()`.
- Central per-platform group resolver `resolveGroupForPlatform()` with chain: `{platform}_group` -> `channel_group` -> provider default.
- Kick streams now have their own check loop and use `kick_group` for grouping.

### Changed
- `handleCheckNow()` no longer concatenates all channel fields into one source. Each platform is parsed independently from its own settings field.
