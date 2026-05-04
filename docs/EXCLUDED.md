# Excluded Platforms

Some platforms have streamlink plugins (or could in principle be scraped) but
are deliberately not supported by Streamarr. The main reason is DRM.

## Why DRM platforms are excluded

Commercial subscription services protect their video streams with DRM systems
such as Widevine (Google) or FairPlay (Apple). The encrypted media segments
require a per-session licence from the platform's licence server before they
can be decoded.

Streamarr relies on `m3u-proxy` and `streamlink` to deliver streams to the
m3u-editor channel. Neither component implements DRM decryption:

- `streamlink` can fetch the manifest and ciphered segments, but it does not
  perform Widevine / FairPlay licence acquisition or CDM-based decryption.
- `m3u-proxy` passes the streamlink output through to the player. It has no DRM
  pipeline of its own.

The result is that even if a streamlink plugin technically matches the URL, the
resulting stream would be unplayable in any IPTV client connected to
m3u-editor. Adding such platforms to Streamarr would only create broken
channels and fail-loop the cleanup logic.

## Platforms intentionally not supported

| Platform        | DRM System        | Notes |
|-----------------|-------------------|-------|
| Disney+         | Widevine / FairPlay | All content is DRM-protected. |
| Netflix         | Widevine / FairPlay / PlayReady | All content is DRM-protected. |
| Prime Video     | Widevine / FairPlay / PlayReady | All content is DRM-protected. |
| Hulu            | Widevine / FairPlay | All content is DRM-protected. |
| Sky / NowTV     | Widevine / PlayReady | DRM-encrypted live and VOD streams. |
| HBO Max / Max   | Widevine / FairPlay | All content is DRM-protected. |
| Paramount+      | Widevine / FairPlay | All content is DRM-protected. |
| Apple TV+       | FairPlay            | All content is DRM-protected. |
| Peacock         | Widevine / FairPlay | DRM-encrypted. |
| DAZN            | Widevine / FairPlay | DRM-encrypted live sport streams. |

Several of these (e.g. Disney+, Netflix, Sky) are not available as streamlink
plugins at all because the upstream project applies the same DRM filter.

## Workarounds (out of scope)

If you have a legal right to record from one of the DRM platforms above (for
example for personal archival in jurisdictions where this is permitted), you
will need a dedicated DRM-aware downloader / ripper that handles Widevine CDM
acquisition and segment decryption. Such tools are intentionally out of scope
for Streamarr and m3u-editor.

The supported workflow is to perform the DRM extraction with an external tool,
store the resulting unencrypted file locally, and then expose it through a
separate HTTP / playlist source if you wish to play it in m3u-editor. Streamarr
will not be involved in that pipeline.

## What about non-DRM but blocked platforms?

A few platforms have no DRM but actively block scraping (aggressive
fingerprinting, Cloudflare interactive challenges, login-walled HLS). These are
not formally excluded; they are simply unreliable. If streamlink can fetch the
URL in your environment, the generic provider will accept it. If the upstream
plugin breaks, Streamarr cannot work around it on its own. Track upstream
status at https://streamlink.github.io/plugins.html.
