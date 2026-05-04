<?php

namespace AppLocalPlugins\Streamarr\Providers;

use AppLocalPlugins\Streamarr\Providers\DTO\MonitoredEntry;
use AppLocalPlugins\Streamarr\Providers\DTO\VodInfo;

/**
 * YouTube live provider stub.
 *
 * Phase 1: identity, URL matching and group resolution. Detection still
 * happens inline in Plugin::handleCheckNow via streamlink + a YouTube
 * specific channel-creator that wires up an EPG entry.
 */
class YouTubeProvider extends BaseProvider
{
    public function id(): string
    {
        return 'youtube';
    }

    public function displayName(): string
    {
        return 'YouTube';
    }

    public function matches(string $rawLine): bool
    {
        return self::isYouTubeUrl(trim($rawLine));
    }

    public function parseEntry(string $rawLine): ?MonitoredEntry
    {
        $line = trim($rawLine);
        if (! self::isYouTubeUrl($line)) {
            return null;
        }

        return new MonitoredEntry(
            provider: $this->id(),
            providerId: $line,
            label: $line,
            rawLine: $line,
        );
    }

    /**
     * Phase 1: streamlink probe is driven inline by the orchestrator.
     *
     * @param  MonitoredEntry[]  $entries
     * @param  array<string,mixed>  $settings
     */
    public function detectLive(array $entries, array $settings, ?string $cookiesFile): array
    {
        return [];
    }

    /**
     * @param  array<string,mixed>  $settings
     */
    public function defaultGroupName(array $settings): string
    {
        $group = isset($settings['youtube_group']) ? trim((string) $settings['youtube_group']) : '';

        return $group !== '' ? $group : 'YouTube Live';
    }

    public function streamUrlFor(MonitoredEntry|VodInfo $target): string
    {
        return $target instanceof VodInfo ? $target->url : $target->providerId;
    }

    private static function isYouTubeUrl(string $line): bool
    {
        return (bool) preg_match('#^https?://(www\.)?(youtube\.com|youtu\.be)/#i', $line);
    }
}
