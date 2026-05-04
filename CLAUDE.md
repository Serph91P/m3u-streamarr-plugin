# CLAUDE.md

Work on `streamarr` as a reviewable plugin artifact for `m3u-editor`.

## Expectations

- Keep the runtime surface centered on `plugin.json` and `Plugin.php`.
- Prefer small, explicit manifest changes over hidden behavior.
- Avoid top-level side effects in PHP files.
- Keep release artifacts reproducible with `bash scripts/package-plugin.sh`.
- Update the published checksum whenever the release zip changes.

## Key design decisions

- No URL refresh: permanent Twitch channel/VOD URL stored; m3u-proxy re-resolves via streamlink on each connection.
- No custom DB table: channel tracking is implicit via `Channel.info->plugin = 'streamarr'`.
- User ID for scheduled runs: derived from the configured StreamProfile's `user_id` when `$context->user` is null.
- Cookies are written to temp files at the start of each action handler and cleaned up before the handler returns.
- Dual detection: Twitch Helix API for fast batch operations when credentials configured; streamlink CLI as zero-config fallback.
- Live dedup: one live channel per Twitch login (Twitch only allows one concurrent live stream per user).
- VOD dedup: by `twitch_vod_id`. multiple VODs per user allowed.
- Game grouping: when `group_mode=game`, channels are grouped by Twitch game/category. Group is updated on each check cycle if the game changes.
- Title updates: existing live channels have their title, game, and thumbnail updated on each check cycle.
- VODs are never auto-cleaned. only live-stream channels are removed when the stream ends.
