<?php

namespace AppLocalPlugins\Streamarr\Providers;

use AppLocalPlugins\Streamarr\Providers\DTO\MonitoredEntry;
use AppLocalPlugins\Streamarr\Providers\DTO\VodInfo;

/**
 * Catch-all streamlink provider.
 *
 * Must be registered LAST so more specific providers (Twitch, YouTube, Kick)
 * have a chance to claim a line first. Accepts any http(s) URL and lets
 * streamlink decide whether the host is supported.
 */
class GenericProvider extends BaseProvider
{
    public function id(): string
    {
        return 'generic';
    }

    public function displayName(): string
    {
        return 'Live Streams';
    }

    public function matches(string $rawLine): bool
    {
        $line = trim($rawLine);

        return $line !== '' && (bool) preg_match('#^https?://#i', $line);
    }

    public function parseEntry(string $rawLine): ?MonitoredEntry
    {
        $line = trim($rawLine);
        if ($line === '' || ! preg_match('#^https?://#i', $line)) {
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
        $group = isset($settings['generic_group']) ? trim((string) $settings['generic_group']) : '';

        return $group !== '' ? $group : 'Live Streams';
    }

    public function streamUrlFor(MonitoredEntry|VodInfo $target): string
    {
        return $target instanceof VodInfo ? $target->url : $target->providerId;
    }
}
