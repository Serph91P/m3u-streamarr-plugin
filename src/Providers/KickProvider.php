<?php

namespace AppLocalPlugins\Streamarr\Providers;

use AppLocalPlugins\Streamarr\Providers\DTO\MonitoredEntry;
use AppLocalPlugins\Streamarr\Providers\DTO\VodInfo;

/**
 * Kick.com live provider stub.
 *
 * Phase 1: identity, URL matching and group resolution. Detection happens
 * inline via streamlink. A dedicated Kick API path is planned for v1.13.
 */
class KickProvider extends BaseProvider
{
    public function id(): string
    {
        return 'kick';
    }

    public function displayName(): string
    {
        return 'Kick';
    }

    public function matches(string $rawLine): bool
    {
        return self::isKickUrl(trim($rawLine));
    }

    public function parseEntry(string $rawLine): ?MonitoredEntry
    {
        $line = trim($rawLine);
        if (! self::isKickUrl($line)) {
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
        $group = isset($settings['kick_group']) ? trim((string) $settings['kick_group']) : '';

        return $group !== '' ? $group : 'Kick Live';
    }

    public function streamUrlFor(MonitoredEntry|VodInfo $target): string
    {
        return $target instanceof VodInfo ? $target->url : $target->providerId;
    }

    private static function isKickUrl(string $line): bool
    {
        return (bool) preg_match('#^https?://(www\.)?kick\.com/#i', $line);
    }
}
