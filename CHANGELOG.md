# Changelog

## [2.3.1] - 2026-05-04

### Fixed
- YouTube multi live regression where only one of several concurrent
  broadcasts ended up enabled. When a legacy row existed for the monitored
  URL, the loop adopted that same row for every sibling in turn and kept
  overwriting it, so the final state only retained one live (whichever was
  processed last). The legacy URL fallback now only adopts a candidate row
  when its stored `youtube_id` is empty (true legacy) or matches the
  current videoId, so additional siblings always create their own row.
- YouTube multi live cap: `search.list` was called with `maxResults=10`,
  silently dropping additional concurrent broadcasts on busy channels
  (e.g. 24/7 lofi radios that run 10 to 20 simultaneous streams). Raised
  to the API maximum of 50 and added `nextPageToken` pagination with a
  hard cap of 5 pages per channel per run, so all live siblings are
  returned. Quota is unchanged for the common case (one page = 100 units)
  and bounded at 500 units per channel in the worst case.

## [2.2.0] - 2026-05-04

### Added
- YouTube multi live support: a single monitored channel/handle URL that is
  broadcasting multiple concurrent live streams (e.g. main + secondary cam,
  multi-language sims, sports multiviews) now produces one channel row per
  broadcast instead of only the first one. Channel identity switched from
  `info.youtube_monitored_url` to `info.youtube_id` (videoId) so siblings
  no longer collide. When two or more siblings exist for the same monitored
  URL their titles are auto-prefixed with `{author} - ` for disambiguation;
  legacy single-live rows keep their existing titles. Cleanup matches each
  channel's stored `youtube_id` against the live list returned for its
  monitored URL, so ended siblings are removed individually. Quota cost is
  unchanged: search.list always charges 100 units regardless of
  `maxResults`.

## [2.1.0] - 2026-05-04

### Added
- `@Handle` shortcut for YouTube channels in the YouTube Channels setting
  (e.g. `@LinusTechTips`). Internally expanded to
  `https://www.youtube.com/@Handle` so all existing detection paths keep
  working unchanged.
- `kick:<slug>` shortcut for Kick channels in the Kick Channels setting
  (e.g. `kick:trainwreckstv`). Internally expanded to
  `https://kick.com/<slug>`. A bare slug without the `kick:` prefix is
  intentionally not accepted because it would conflict with Twitch bare
  logins (backwards compatibility).

## [2.0.0] - 2026-05-04

### Added
- Multi-platform support via a new `PlatformProvider` abstraction. Providers
  are discovered and dispatched through a central `ProviderRegistry`.
- `GenericProvider` as a catch-all for any URL supported by streamlink. Covers
  100+ tier-2 platforms including DLive, TikTok, Picarto, Soop (afreecatv),
  Huya, Bilibili, Bigo Live, Steam Broadcast, Vimeo Events, Dailymotion,
  Trovo, Rumble, Pluto.tv, Crunchyroll, NimoTV, Mildom, Mixcloud, Nico Nico
  Douga, OK.ru, public broadcasters (ARD, ZDF, RTVE, RaiPlay, BBC iPlayer,
  RTPplay, France.tv) and others.
- `KickProvider` rewritten on the Kick API v2. Includes optional VOD
  enumeration via `/api/v2/channels/{slug}/videos`, gated by the
  `Include Kick VODs` toggle and `Max Kick VODs per Channel` setting.
  Streamlink fallback is preserved when the Kick API is unreachable.
- `YouTubeProvider` with optional Data API v3 integration plus streamlink
  fallback. When a key is configured, `search.list` is used for channel and
  handle URLs and `videos.list?part=liveStreamingDetails` is used for watch
  URLs. Without a key, behaviour is byte-identical to v1.x.
- Host-based log labels for the generic provider so multi-platform runs
  remain readable in logs.
- YouTube cleanup loop now uses the Data API when a key is set, with a
  conservative streamlink confirmation before deleting a channel that was
  created during a live session.
- New documentation: `docs/PLATFORMS.md` (full platform matrix grouped by
  tier) and `docs/EXCLUDED.md` (DRM-protected services that are
  intentionally not supported).

### Changed
- Major version bump to **2.0.0** to signal Streamarr's new multi-platform
  identity. The plugin is no longer Twitch-only.
- `README.md` rewritten for multi-platform usage: new intro, supported
  platforms section, channel input format reference, YouTube API setup
  guide, and an updated settings overview.

### Notes
- Backward compatible release. No database migrations, no settings renames
  and no `plugin.json` permission changes. All previously monitored channels
  (Twitch, YouTube, Kick, generic) continue to work without intervention.
- Provider behaviour without API keys is unchanged from v1.15.0; API keys
  remain strictly optional on all platforms that have one.

## [1.15.0] - 2026-05-04

### Added
- Optional `YouTube Data API v3 Key` setting in the YouTube section. When provided, `YouTubeProvider::detectLive()` resolves channel handles, calls `search.list?eventType=live` for channelId entries and `videos.list?part=liveStreamingDetails` for `watch?v=` entries, returning the same info shape that the existing channel-creator consumes. Faster than spawning streamlink per URL and avoids HTTP 429 throttling when monitoring many channels.
- Streamlink fallback per URL is preserved: legacy `/c/` paths, handle-resolution failures, single-call HTTP errors and network errors push the URL into the provider's `pendingFallback` bucket; the orchestrator drains it through `checkYouTubeLiveViaStreamlink()` exactly as today.
- On `403 quotaExceeded` the provider logs one warning and drains all remaining URLs to streamlink for the rest of the run. On `400 keyInvalid` it logs one warning and drains the entire run to streamlink.

### Notes
- Quota cost: `search.list` is 100 units per call. The default 10000 daily quota covers roughly 100 channel checks per day. Heavier usage requires either fewer channels or a quota increase from Google.
- API key never appears in logs.
- Without a key, behaviour is byte-identical to v1.14.0 (every YouTube URL is probed via streamlink, just like before).
- The YouTube cleanup loop (ended-stream detection on already-live channels) still uses streamlink. Marked with a TODO for a follow-up.

### Unchanged
- Twitch and Kick code paths are untouched.
- No new permissions, no new database tables, no new migrations.

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
