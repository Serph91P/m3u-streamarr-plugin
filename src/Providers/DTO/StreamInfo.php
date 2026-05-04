<?php

namespace AppLocalPlugins\Streamarr\Providers\DTO;

/**
 * Live-stream snapshot returned by PlatformProvider::detectLive() for a single
 * monitored entry. `isLive=false` means the provider checked and the channel
 * is currently offline (still useful for cleanup decisions).
 */
class StreamInfo
{
    public function __construct(
        /** MonitoredEntry::key() this result belongs to. */
        public readonly string $entryKey,

        public readonly bool $isLive,

        /** Stream title / video title (live only). Null when offline. */
        public readonly ?string $title = null,

        /** Category / game / topic, when available. */
        public readonly ?string $category = null,

        /** Author / channel display name, when different from MonitoredEntry::label. */
        public readonly ?string $author = null,

        /** Thumbnail / preview image URL (often time-stamped). */
        public readonly ?string $thumbnailUrl = null,

        /** Provider-specific stream URL streamlink/m3u-proxy resolves on each connect. */
        public readonly ?string $streamUrl = null,

        /** Provider-specific stream id (e.g. Twitch stream id, YouTube video id). */
        public readonly ?string $streamId = null,

        /** Optional started_at ISO8601. used for EPG and 'started X minutes ago' UI. */
        public readonly ?string $startedAt = null,

        /** Free-form extras the orchestrator can persist into Channel.info. */
        public readonly array $extras = [],
    ) {
    }
}
