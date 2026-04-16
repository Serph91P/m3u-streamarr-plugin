# AGENTS.md

This repository builds the `streamarr` plugin for `m3u-editor`.

## Guardrails

- Keep `plugin.json` and `Plugin.php` as the runtime source of truth.
- Do not add top-level executable code outside the plugin class.
- Keep runtime files reviewable and minimal.
- Do not widen manifest permissions without updating the README and release notes.
- Package only runtime files for release artifacts.
- The plugin uses no custom database tables — channel tracking is via `Channel.info->plugin = 'streamarr'`.

## Security

- GitHub CI is a quality signal, not a trust boundary.
- The host still performs reviewed install, ClamAV scanning, explicit trust, and integrity verification.
- `network_egress` is required for Twitch Helix API calls and streamlink subprocess outbound connections.
- `filesystem_write` is required for temporary cookie files during plugin runs.
- Cookies are written to temp files during plugin runs and deleted before the action handler returns. Never persist cookies to permanent storage.
- Twitch API credentials (client_id, client_secret) are stored in plugin settings — never log them.

## Streamlink subprocess

- All streamlink calls go through `runProcess()` which uses the Laravel Process facade with an explicit timeout.
- `findStreamlink()` checks common paths — do not hardcode `/usr/bin/streamlink`.

## Twitch API

- App Access Tokens are obtained via client_credentials grant and cached in memory for the duration of a single action run.
- Batch endpoints (streams, users) process up to 100 logins per request to minimize API calls.
- Token is cleared (`$this->accessToken = null`) at the end of each action handler.
