# Changelog

## [1.13.1] - 2026-05-04

### Fixed
- `plugin.json`: `include_kick_vods` and `kick_use_streamlink_fallback` used `type: toggle`, which the plugin manifest validator rejects. Changed to `type: boolean` (same as the existing Twitch `include_vods` toggle).

## [1.13.0] - 2026-05-04

### Added
- Kick is now a Tier-1 provider. Live detection uses the public Kick API (`https://kick.com/api/v2/channels/{slug}`) instead of streamlink probing, which is faster and avoids spawning a subprocess per channel.
- Optional Kick VOD discovery via `https://kick.com/api/v2/channels/{slug}/videos`. Gated by the new `Include Kick VODs` toggle and `Max Kick VODs per Channel` (1-50) setting; defaults to OFF. Twitch's `include_vods` toggle does not affect Kick.
- New optional `Kick VOD Group Label` setting; falls back to the Kick live group plus a `VODs` suffix.
- New `Force Streamlink for Kick` toggle for environments where the Kick API is consistently blocked by Cloudflare.

### Changed
- Streamlink fallback is retained: when the Kick API call fails (network error, non-2xx response, JSON decode error, or the force-streamlink toggle is on), the orchestrator falls back to the existing inline streamlink probe per URL.
- The provider-driven loop now invokes `KickProvider::detectLive()` for Kick; YouTube and Generic continue to use the inline streamlink probe until their providers grow real detection in later phases.

### Unchanged
- Twitch live and VOD code paths are unchanged.
- No new permissions, no new database tables. Existing `kick_channels` and `kick_group` settings keep working as before.

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
