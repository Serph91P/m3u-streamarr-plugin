<?php

namespace AppLocalPlugins\Streamarr\Providers;

use AppLocalPlugins\Streamarr\Providers\DTO\MonitoredEntry;
use AppLocalPlugins\Streamarr\Providers\DTO\VodInfo;

/**
 * Base implementation of PlatformProvider with sensible defaults.
 *
 * Subclasses override only what they need beyond streamlink-probe baseline.
 * The orchestrator (Plugin::handleCheckNow) treats every non-Twitch provider
 * as a streamlink-driven probe in Phase 1; richer per-provider detection
 * (Kick API, YouTube Data API, Tier-2 platforms) lands in later phases.
 */
abstract class BaseProvider implements PlatformProvider
{
    abstract public function id(): string;

    abstract public function displayName(): string;

    abstract public function matches(string $rawLine): bool;

    abstract public function parseEntry(string $rawLine): ?MonitoredEntry;

    public function supportsBatchDetection(): bool
    {
        return false;
    }

    /**
     * Phase 1: detection is still performed inline by the orchestrator using
     * `streamlink --json`. Subclasses can override once their dedicated
     * detection backend lands.
     *
     * @param  MonitoredEntry[]  $entries
     * @param  array<string,mixed>  $settings
     */
    abstract public function detectLive(array $entries, array $settings, ?string $cookiesFile): array;

    public function supportsVods(): bool
    {
        return false;
    }

    /**
     * @param  array<string,mixed>  $settings
     * @return VodInfo[]
     */
    public function listVods(MonitoredEntry $entry, int $limit, array $settings): array
    {
        return [];
    }

    /**
     * @param  array<string,mixed>  $settings
     */
    public function fetchLogo(MonitoredEntry $entry, array $settings): ?string
    {
        return null;
    }

    /**
     * @param  array<string,mixed>  $settings
     */
    abstract public function defaultGroupName(array $settings): string;

    abstract public function streamUrlFor(MonitoredEntry|VodInfo $target): string;
}
