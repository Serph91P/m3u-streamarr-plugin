# Streamarr. Multi-Platform Live Stream Plugin for m3u-editor

Monitor 100+ streaming platforms (Twitch, YouTube, Kick, plus dozens of
streamlink-supported tier-2 platforms) for live content and Twitch / Kick VODs.
Streamarr automatically creates and removes channels in
[m3u-editor](https://github.com/m3ue/m3u-editor). Streams are played through
[m3u-proxy](https://github.com/m3ue/m3u-proxy)'s streamlink backend.

## Features

- **Multi-platform support**. Twitch, YouTube and Kick are first-class
  providers. Any other platform supported by streamlink can be added through
  the generic provider (DLive, TikTok, Picarto, Vimeo, Dailymotion, Bilibili,
  Trovo, Rumble, Pluto.tv, public broadcasters, etc.).
- **Provider tiers**. Tier-1 providers use native APIs (Twitch Helix, YouTube
  Data API v3, Kick API v2) for fast batch detection and rich metadata.
  Tier-2 platforms route through streamlink for live detection.
- **Optional API keys**. Twitch Helix (Client ID + Secret) and YouTube Data
  API v3 (key) are optional. Without keys, streamlink probing is used, which
  is slower for large channel lists but requires zero credentials.
- **VOD support**. Recent VODs for monitored Twitch and Kick channels can be
  imported automatically.
- **Live title and game updates**. Existing live channels are refreshed on
  each cycle when the title or game / category changes.
- **Auto-cleanup**. Live channels are removed when the stream ends. The
  YouTube cleanup loop uses the Data API when a key is set, with a
  conservative streamlink confirmation before delete.
- **Scheduled monitoring**. Configurable cron schedule for automatic checks.
- **Channel numbering**. Sequential or decimal numbering modes, with optional
  Xtream-friendly bounded ranges.

## Requirements

- **m3u-editor** with the plugin system enabled
- **m3u-proxy** with streamlink support (`STREAMLINK_ENABLED=true`)
- **Twitch API credentials** (optional). See [Twitch API Setup](#twitch-api-setup)
- **YouTube Data API v3 key** (optional). See [YouTube API Setup](#youtube-api-setup)

## Installation

1. Download the latest release archive (`streamarr.zip`)
2. Install via m3u-editor's plugin management UI or CLI:
   ```bash
   php artisan plugins:stage-archive /path/to/streamarr.zip
   php artisan plugins:scan-install streamarr
   php artisan plugins:approve-install streamarr --trust
   ```
3. Enable the plugin and configure settings

## Supported Platforms

Streamarr ships with three Tier-1 providers and a generic Tier-2 catch-all that
routes through streamlink. Highlights:

- **Tier 1**: Twitch (Helix API + streamlink), YouTube (optional Data API v3 +
  streamlink fallback), Kick (Kick API v2 + streamlink fallback).
- **Tier 2**: any platform with a streamlink plugin, including DLive, TikTok,
  Picarto, Soop (afreecatv), Huya, Bilibili, Bigo Live, Steam Broadcast, Vimeo
  Events, Dailymotion, Trovo, Rumble, Pluto.tv, public broadcasters
  (ARD / ZDF / RTVE / RaiPlay / BBC iPlayer / RTPplay / France.tv) and many
  more.

For the full list of supported platforms see [docs/PLATFORMS.md](docs/PLATFORMS.md).
For platforms that are intentionally not supported (mostly DRM services such
as Disney+, Netflix, Prime Video) see [docs/EXCLUDED.md](docs/EXCLUDED.md).

## Channel Input Format

Each platform has its own input field in the settings. One entry per line.
Lines starting with `#` are ignored.

- **Twitch**: bare logins are accepted as a shortcut (e.g. `pokimane`).
  Decimal numbering supported via `username=BaseNumber`. VOD URLs
  (`https://twitch.tv/videos/<id>`) are also accepted via the manual action.
- **YouTube**: bare `@Handle` shortcut (e.g. `@LinusTechTips`) or a full URL.
  Channel handles (`https://www.youtube.com/@Handle`), channel IDs
  (`https://www.youtube.com/channel/UCxxxx`) and watch URLs
  (`https://www.youtube.com/watch?v=xxxx`) are supported. A bare handle
  without the leading `@` is intentionally not accepted because it would
  collide with Twitch bare logins.
- **Kick**: `kick:<slug>` shortcut (e.g. `kick:trainwreckstv`) or a full URL
  (`https://kick.com/<slug>`). A bare slug without the `kick:` prefix is
  intentionally not accepted because it would collide with Twitch bare
  logins.
- **Generic / Other Platforms**: full URL only. Anything streamlink recognises
  is accepted; Streamarr derives a host-based label from the URL for logging.

## Twitch API Setup

Without API credentials, Streamarr uses streamlink to check each Twitch channel
individually. This works but is **slow for more than ~20 channels**. The Twitch
Helix API can batch-check up to 100 channels per request.

### Getting Twitch API credentials

1. Go to the [Twitch Developer Console](https://dev.twitch.tv/console/apps)
2. Click **Register Your Application**
3. Fill in:
   - **Name**: anything (e.g. "m3u-editor streamarr")
   - **OAuth Redirect URLs**: `http://localhost` (not actually used)
   - **Category**: Application Integration
4. Click **Create**
5. Copy the **Client ID**
6. Click **New Secret** and copy the **Client Secret**
7. Enter both values in the Streamarr plugin settings (Twitch section)

> **Note:** The plugin uses an App Access Token (client_credentials grant). No
> user login or OAuth redirect flow is needed. Only public data is accessed
> (live streams, user profiles, public VODs).

## YouTube API Setup

Without an API key, Streamarr probes each YouTube URL via streamlink. This
works but is slow for large channel lists and may hit HTTP 429 rate limits
when monitoring many channels. The YouTube Data API v3 is faster and
rate-limit-friendly.

### Getting a YouTube API key

1. Open the [Google Cloud Console](https://console.cloud.google.com/)
2. Create or select a project
3. Open **APIs and Services > Library**, search for **YouTube Data API v3**
   and click **Enable**
4. Open **APIs and Services > Credentials**, click **Create Credentials > API
   key**
5. Copy the generated key
6. Paste it into the Streamarr plugin setting **YouTube Data API v3 Key**
   (YouTube section)

### Quota note

The default project quota is **10000 units per day**. Streamarr uses
`search.list` for channel handle / channel-id entries, which costs **100 units
per call**. That means roughly **100 channel checks per day** with the default
quota. For heavier usage, either reduce the channel list, lengthen the
monitor schedule, or request a quota increase from Google.

When the quota is exhausted (HTTP 403 `quotaExceeded`) Streamarr logs one
warning and falls back to streamlink for the rest of the run. Without a key
the behaviour is unchanged from v1.x (everything goes through streamlink).

## Settings Overview

Settings are grouped into collapsible sections in the plugin UI:

| Section | Purpose |
|---------|---------|
| Core Setup | Stream Profile (must use the Streamlink backend), target playlists. |
| Stream and Live Defaults | Global defaults: live group name, group mode, EPG mode, title mode, quality, output format, auto-cleanup. |
| Twitch | Twitch channels, group label, VOD options, Helix API credentials. |
| YouTube | YouTube channel URLs, group label, optional Data API v3 key. |
| Kick | Kick channel URLs, group label, VOD options, force-streamlink toggle. |
| Generic / Other Platforms | Any other streamlink-supported URL, group label. |
| Numbering and Xtream | Starting number, increment, sequential vs decimal mode, Xtream compatibility bounds, ASCII text mode. |
| Automation | Enable scheduled monitoring, cron expression. |

## Channel Numbering

### Sequential Mode (default)

Channels are numbered sequentially from the starting number:

```
900, 901, 902, 903 ...
```

### Decimal Mode

Group streams from the same Twitch channel using base numbers:

```
pokimane=50   -> 50.1 (live), 50.2 (VOD), 50.3 (VOD) ...
shroud=60     -> 60.1 (live), 60.2 (VOD) ...
```

Configure base numbers in the Twitch Channels setting: `username=BaseNumber`.

## Performance (Twitch)

| Channels | With Helix API | Without API (streamlink) |
|----------|----------------|--------------------------|
| 1-20     | < 1 sec        | ~30 sec                  |
| 50       | < 1 sec        | ~2 min                   |
| 100      | < 2 sec        | ~5 min                   |
| 200+     | < 3 sec        | Very slow, API strongly recommended |

YouTube Data API v3 has comparable speed when a key is configured. Tier-2
platforms are always probed individually via streamlink.

## How It Works

1. **Detection**: Each platform's provider checks which monitored URLs are
   live. Tier-1 providers prefer their native API, Tier-2 uses streamlink.
2. **Channel creation**: m3u-editor channels are created with the permanent
   platform URL (e.g. `https://twitch.tv/<login>`,
   `https://kick.com/<slug>`).
3. **Proxy resolution**: When a viewer connects, m3u-proxy uses streamlink to
   resolve the URL to a live HLS stream in real time.
4. **Updates**: On each cycle, live channel titles and games are updated if
   they changed.
5. **Cleanup**: Ended streams are detected and their channels removed (live
   only; VOD channels are preserved).

## Troubleshooting

### "Failed to obtain Twitch API access token"
- Verify your Client ID and Client Secret are correct.
- Check that the Twitch application has not been revoked at
  https://dev.twitch.tv/console/apps.

### "YouTube Data API: quotaExceeded"
- The 10000 daily-unit quota is exhausted. Streamarr falls back to streamlink
  automatically for the rest of the run. Reduce the channel list, lengthen
  the schedule, or request a quota increase.

### Channels created but streams do not play
- Ensure `STREAMLINK_ENABLED=true` in m3u-proxy configuration.
- Verify the Stream Profile is configured for streamlink (not yt-dlp).
- Check m3u-proxy logs for streamlink errors.

### VODs not appearing
- Twitch VOD discovery requires Twitch API credentials and the
  `Include Twitch VODs` toggle.
- Kick VOD discovery requires the `Include Kick VODs` toggle.
- Some streamers disable VOD saving on the platform side.

### Generic platform URL is not detected
- Run `streamlink --plugins` to verify the platform is supported by your
  installed streamlink build.
- Some platforms are DRM-protected and intentionally excluded; see
  [docs/EXCLUDED.md](docs/EXCLUDED.md).

## License

MIT
