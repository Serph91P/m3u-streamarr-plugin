<?php

namespace AppLocalPlugins\Streamarr\Providers\DTO;

/**
 * Normalized representation of a single monitored entry parsed from the
 * `monitored_channels` textarea (one line). Each provider parses raw lines
 * into this DTO via PlatformProvider::parseEntry().
 */
class MonitoredEntry
{
    public function __construct(
        /** Provider id (e.g. 'twitch', 'youtube', 'kick'). */
        public readonly string $provider,

        /** Stable provider-specific id used for de-duplication and Channel.info storage.
         *  Examples: twitch login (lowercase), youtube channel/video URL, kick slug. */
        public readonly string $providerId,

        /** Human-readable label used as initial channel title until live metadata arrives. */
        public readonly string $label,

        /** Raw line as written by the user (for diagnostics / round-tripping). */
        public readonly string $rawLine,

        /** Optional decimal numbering base (`username=50`). Null if not specified. */
        public readonly ?int $baseNumber = null,

        /** Free-form provider-specific extras (e.g. youtube url variant, twitch flags). */
        public readonly array $extras = [],
    ) {
    }

    /**
     * Stable key used by ProviderRegistry to map detection results back to entries.
     * Format: "<provider>:<providerId>".
     */
    public function key(): string
    {
        return $this->provider.':'.$this->providerId;
    }
}
