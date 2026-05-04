# Supported Platforms

Streamarr v2.0.0 monitors live streams (and where supported, VODs) across 100+
streaming platforms. Platforms are organised into two tiers:

- **Tier 1**: dedicated provider with native API integration. Fast, batchable,
  rate-limit aware, and supports rich metadata (titles, games/categories,
  thumbnails, VOD listings where applicable).
- **Tier 2**: handled by the generic provider through `streamlink`. Live
  detection works for any URL that streamlink recognises. No batch API, no VOD
  enumeration, metadata is limited to what streamlink reports.

## How to add channels

In the plugin settings each platform has its own input field. Add one URL per
line. Lines starting with `#` are ignored. For Twitch you may also enter a bare
login (e.g. `pokimane`); every other platform requires the full URL.

Tier-2 URLs go into the `Generic / Other Platforms` field. Streamarr inspects
the host of each URL to derive a log label and routes the URL to streamlink for
detection.

## Tier 1 platforms

| Platform | URL Pattern Examples | Tier | Live Detection | VOD Support | Notes |
|----------|----------------------|------|----------------|-------------|-------|
| Twitch  | `pokimane`, `https://twitch.tv/pokimane`, `https://twitch.tv/videos/123456789` | 1 | Helix API (batch up to 100) + streamlink fallback | Yes (Helix API, recent VODs per channel) | API credentials optional but strongly recommended for >20 channels. Game-based grouping requires API. |
| YouTube | `https://www.youtube.com/@Handle`, `https://www.youtube.com/channel/UCxxxx`, `https://www.youtube.com/watch?v=xxxx` | 1 | Data API v3 (optional) + streamlink fallback | Planned | Without API key, streamlink probes each URL. With key: `search.list` for channels, `videos.list` for watch URLs. Quota: 100 units per channel check. |
| Kick    | `https://kick.com/<slug>` | 1 | Kick API v2 (no key) + streamlink fallback | Yes (Kick API v2, recent past broadcasts) | Live detection is keyless. A `Force Streamlink for Kick` toggle exists for environments where the Kick API is blocked by Cloudflare. |

## Tier 2 platforms (generic provider via streamlink)

All entries below are tested or known-working streamlink 8.3.0 plugins. For every
Tier-2 platform: live detection only, no VOD enumeration, no native metadata
beyond what streamlink reports. Use the direct stream URL.

| Platform | URL Pattern Examples | Tier | Live Detection | VOD Support | Notes |
|----------|----------------------|------|----------------|-------------|-------|
| DLive            | `https://dlive.tv/<channel>` | 2 | streamlink | No | use direct stream URL |
| TikTok           | `https://www.tiktok.com/@<user>/live` | 2 | streamlink | No | use direct stream URL |
| Picarto          | `https://picarto.tv/<channel>` | 2 | streamlink | No | use direct stream URL |
| Soop (afreecatv) | `https://afreecatv.com/<channel>` | 2 | streamlink | No | rebranded from afreecatv; use direct stream URL |
| Huya             | `https://www.huya.com/<id>` | 2 | streamlink | No | use direct stream URL |
| Bilibili         | `https://live.bilibili.com/<id>`, `https://www.bilibili.com/<id>` | 2 | streamlink | No | use direct stream URL |
| Bigo Live        | `https://www.bigo.tv/<id>` | 2 | streamlink | No | use direct stream URL |
| Steam Broadcast  | `https://steamcommunity.com/broadcast/watch/<steamid>` | 2 | streamlink | No | use direct stream URL |
| Vimeo Events     | `https://vimeo.com/event/<id>`, `https://vimeo.com/<id>` | 2 | streamlink | No | use direct stream URL |
| Dailymotion      | `https://www.dailymotion.com/video/<id>` | 2 | streamlink | No | use direct stream URL |
| BBC iPlayer      | `https://www.bbc.co.uk/iplayer/live/<channel>` | 2 | streamlink | No | geo-restricted; use direct stream URL |
| Crunchyroll      | `https://www.crunchyroll.com/watch/<id>` | 2 | streamlink | No | use direct stream URL; subscription content may not work |
| Mildom           | `https://www.mildom.com/<id>` | 2 | streamlink | No | use direct stream URL |
| Mixcloud         | `https://www.mixcloud.com/live/<channel>` | 2 | streamlink | No | use direct stream URL |
| NimoTV           | `https://www.nimo.tv/<channel>` | 2 | streamlink | No | use direct stream URL |
| Nico Nico Douga  | `https://www.nicovideo.jp/watch/<id>`, `https://live.nicovideo.jp/watch/<id>` | 2 | streamlink | No | use direct stream URL |
| Trovo            | `https://trovo.live/s/<channel>` | 2 | streamlink | No | use direct stream URL |
| OK.ru            | `https://ok.ru/live/<id>`, `https://ok.ru/video/<id>` | 2 | streamlink | No | use direct stream URL |
| Rumble           | `https://rumble.com/<video-id>` | 2 | streamlink | No | use direct stream URL |
| Pluto.tv         | `https://pluto.tv/live-tv/<slug>` | 2 | streamlink | No | use direct stream URL |
| Atresplayer      | `https://www.atresplayer.com/directos/<channel>` | 2 | streamlink | No | geo-restricted ES; use direct stream URL |
| RaiPlay          | `https://www.raiplay.it/dirette/<channel>` | 2 | streamlink | No | geo-restricted IT; use direct stream URL |
| RTVE             | `https://www.rtve.es/play/videos/directo/<channel>` | 2 | streamlink | No | geo-restricted ES; use direct stream URL |
| ARD Mediathek    | `https://www.ardmediathek.de/live/<id>` | 2 | streamlink | No | geo-restricted DE; use direct stream URL |
| ZDF Mediathek    | `https://www.zdf.de/live-tv` | 2 | streamlink | No | geo-restricted DE; use direct stream URL |
| Zattoo           | `https://zattoo.com/watch/<channel>` | 2 | streamlink | No | account required; use direct stream URL |
| Welt             | `https://www.welt.de/tv/` | 2 | streamlink | No | use direct stream URL |
| n-tv             | `https://www.n-tv.de/mediathek/livestream/` | 2 | streamlink | No | use direct stream URL |
| RTPplay          | `https://www.rtp.pt/play/direto/<channel>` | 2 | streamlink | No | geo-restricted PT; use direct stream URL |
| Douyu            | `https://www.douyu.com/<id>` | 2 | streamlink | No | use direct stream URL |
| Euronews         | `https://www.euronews.com/live` | 2 | streamlink | No | use direct stream URL |
| France.tv        | `https://www.france.tv/<channel>/direct.html` | 2 | streamlink | No | geo-restricted FR; use direct stream URL |
| TVP              | `https://stream.tvp.pl/?channel_id=<id>` | 2 | streamlink | No | geo-restricted PL; use direct stream URL |
| ABweb            | `https://www.abweb.com/live` | 2 | streamlink | No | use direct stream URL |
| Adult Swim       | `https://www.adultswim.com/streams` | 2 | streamlink | No | geo-restricted US; use direct stream URL |
| Bloomberg        | `https://www.bloomberg.com/live` | 2 | streamlink | No | use direct stream URL |
| CDNbg            | various Bulgarian broadcasters | 2 | streamlink | No | use direct stream URL |
| Filmon           | `https://www.filmon.com/tv/<channel>` | 2 | streamlink | No | use direct stream URL |
| Goodgame         | `https://goodgame.ru/channel/<channel>/` | 2 | streamlink | No | use direct stream URL |
| TVRplus          | `https://www.tvrplus.ro/live/<channel>` | 2 | streamlink | No | geo-restricted RO; use direct stream URL |

This list is not exhaustive. Streamlink 8.3.0 ships roughly 115 plugins.
DRM-protected platforms (Disney+, Netflix, Prime Video, Hulu, Sky / NowTV DRM
streams, HBO Max, Paramount+, Apple TV+, etc.) are excluded by design, see
[EXCLUDED.md](EXCLUDED.md).

## Discovering the full plugin list

To see every platform your installed streamlink build supports, run:

```bash
streamlink --plugins
```

Any plugin listed there should work as a Tier-2 entry, with the DRM caveats
documented in [EXCLUDED.md](EXCLUDED.md). Platform support changes between
streamlink releases; check the upstream catalogue at
https://streamlink.github.io/plugins.html for current status.
