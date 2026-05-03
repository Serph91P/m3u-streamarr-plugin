<?php

namespace AppLocalPlugins\Streamarr\Providers\DTO;

/**
 * Single VOD / archived recording exposed by a provider that supports VOD listing.
 * Used by PlatformProvider::listVods().
 */
class VodInfo
{
    public function __construct(
        /** Provider id this VOD belongs to. */
        public readonly string $provider,

        /** Provider-specific VOD id (e.g. Twitch video id). Used for deduplication. */
        public readonly string $vodId,

        public readonly string $title,

        /** Permanent VOD URL streamlink resolves on each connect. */
        public readonly string $url,

        /** Optional channel/author label. */
        public readonly ?string $author = null,

        /** Game / category, when known. */
        public readonly ?string $category = null,

        /** Thumbnail URL, when known. */
        public readonly ?string $thumbnailUrl = null,

        /** Duration in seconds, when known. */
        public readonly ?int $durationSeconds = null,

        /** Original publish date ISO8601, when known. */
        public readonly ?string $publishedAt = null,

        /** Free-form extras to persist into Channel.info. */
        public readonly array $extras = [],
    ) {
    }
}
