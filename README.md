# Streamarr — Twitch Plugin for m3u-editor

Monitor Twitch channels for live streams and VODs, automatically create and remove channels in [m3u-editor](https://github.com/m3ue/m3u-editor). Streams are played through [m3u-proxy](https://github.com/m3ue/m3u-proxy)'s streamlink backend.

## Features

- **Live stream detection** — Monitor Twitch channels and auto-create m3u-editor channels when they go live
- **VOD support** — Optionally import recent VODs for each monitored channel
- **Dual detection backend** — Fast batch detection via Twitch Helix API, or zero-config streamlink CLI fallback
- **Game-based grouping** — Group live channels by their current Twitch game/category (e.g. "Just Chatting", "Fortnite")
- **Live title & game updates** — Existing live channels are updated on each check cycle when the title or game changes
- **Auto-cleanup** — Automatically remove channels when the stream ends
- **Scheduled monitoring** — Configurable cron schedule for automatic checks
- **Channel numbering** — Sequential or decimal numbering modes (same as YouTubearr)

## Requirements

- **m3u-editor** with the plugin system enabled
- **m3u-proxy** with streamlink support (`STREAMLINK_ENABLED=true`)
- **Twitch API credentials** (optional but recommended) — see [Setup](#twitch-api-setup)

## Installation

1. Download the latest release archive (`streamarr.zip`)
2. Install via m3u-editor's plugin management UI or CLI:
   ```bash
   php artisan plugins:stage-archive /path/to/streamarr.zip
   php artisan plugins:scan-install streamarr
   php artisan plugins:approve-install streamarr --trust
   ```
3. Enable the plugin and configure settings

## Twitch API Setup

Without API credentials, Streamarr uses streamlink to check each channel individually. This works but is **slow for more than ~20 channels**. The Twitch Helix API can batch-check up to 100 channels in a single request.

### Getting API Credentials

1. Go to the [Twitch Developer Console](https://dev.twitch.tv/console/apps)
2. Click **Register Your Application**
3. Fill in:
   - **Name**: anything (e.g. "m3u-editor streamarr")
   - **OAuth Redirect URLs**: `http://localhost` (not actually used)
   - **Category**: Application Integration
4. Click **Create**
5. Copy the **Client ID**
6. Click **New Secret** and copy the **Client Secret**
7. Enter both values in the Streamarr plugin settings

> **Note:** The plugin uses an App Access Token (client_credentials grant) — no user login or OAuth redirect flow is needed. This only accesses public data (live streams, user profiles, public VODs).

## Settings Reference

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| Monitored Channels | textarea | — | Twitch usernames, one per line. `username` or `username=BaseNumber` for decimal numbering. `#` for comments. |
| Stream Profile | model_select | required | Streamlink Stream Profile for proxy playback and cookies |
| Target Playlist (Standard) | model_select | — | Standard playlist to link created channels |
| Target Playlist (Custom) | model_select | — | Custom playlist to add created channels |
| Group Mode | select | `static` | `static` (fixed name) or `game` (by Twitch game/category) |
| Live Channel Group | text | `Twitch Live` | Group name in static mode / fallback for game mode |
| VOD Group | text | `Twitch VODs` | Group name for VOD channels |
| Stream Quality | select | `best` | Preferred quality: best, 1080p60, 720p60, 720p, 480p, 360p, 160p, audio_only |
| Include VODs | boolean | `false` | Import recent VODs (requires Twitch API) |
| VOD Limit | number | `5` | Max VODs per channel |
| Auto-cleanup | boolean | `true` | Remove live channels when stream ends |
| Starting Channel Number | number | `3000` | First channel number |
| Channel Number Increment | number | `1` | Increment per new channel |
| Channel Numbering Mode | select | `sequential` | `sequential` or `decimal` |
| Twitch Client ID | text | — | Twitch API Client ID (optional) |
| Twitch Client Secret | text | — | Twitch API Client Secret (optional) |
| Enable Monitoring | boolean | `false` | Enable scheduled automatic checks |
| Monitor Schedule | text | `*/10 * * * *` | Cron expression for auto-check |

## Channel Numbering

### Sequential Mode (default)
Channels are numbered sequentially from the starting number:
```
3000, 3001, 3002, 3003…
```

### Decimal Mode
Group streams by Twitch channel using base numbers:
```
pokimane=50   → 50.1 (live), 50.2 (VOD), 50.3 (VOD)…
shroud=60     → 60.1 (live), 60.2 (VOD)…
```

Configure base numbers in the Monitored Channels setting: `username=BaseNumber`

## Performance

| Channels | With API | Without API (streamlink) |
|----------|----------|-------------------------|
| 1–20 | < 1 sec | ~30 sec |
| 50 | < 1 sec | ~2 min |
| 100 | < 2 sec | ~5 min |
| 200+ | < 3 sec | ⚠ Very slow, API strongly recommended |

## How It Works

1. **Detection**: Checks which monitored channels are live (via Twitch API batch or streamlink per-channel)
2. **Channel creation**: Creates m3u-editor channels with permanent Twitch URLs (`https://twitch.tv/username`)
3. **Proxy resolution**: When a viewer connects, m3u-proxy uses streamlink to resolve the Twitch URL to a live HLS stream in real-time
4. **Updates**: On each check cycle, live channel titles and games are updated if changed
5. **Cleanup**: Ended streams are automatically detected and their channels removed

## Troubleshooting

### "Failed to obtain Twitch API access token"
- Verify your Client ID and Client Secret are correct
- Check that the Twitch application hasn't been revoked at https://dev.twitch.tv/console/apps

### Channels created but streams don't play
- Ensure `STREAMLINK_ENABLED=true` in m3u-proxy configuration
- Verify the Stream Profile is configured for streamlink (not yt-dlp)
- Check m3u-proxy logs for streamlink errors

### VODs not appearing
- VOD discovery requires Twitch API credentials
- Enable "Include VODs" in settings
- Some streamers disable VOD saving

## License

MIT
