<?php

namespace AppLocalPlugins\Streamarr\Providers;

use AppLocalPlugins\Streamarr\Providers\DTO\MonitoredEntry;
use AppLocalPlugins\Streamarr\Providers\DTO\StreamInfo;
use AppLocalPlugins\Streamarr\Providers\DTO\VodInfo;

/**
 * Contract every platform provider (Twitch, YouTube, Kick, Generic-streamlink, …)
 * implements. The orchestrator (Plugin.php) parses each line from the
 * `monitored_channels` textarea, dispatches it to the matching provider,
 * collects StreamInfo results in batch, and persists them as Channel rows.
 *
 * Conventions:
 * - `id()` is the stable string used in `Channel.info->provider` and as the
 *   prefix in MonitoredEntry::key(). Lowercase, no spaces.
 * - All array-returning methods return empty arrays (never null) on no-result.
 * - Implementations MUST NOT throw on individual entry failure. they should
 *   omit the entry from the result and let the orchestrator log the gap.
 */
interface PlatformProvider
{
    /** Stable provider id, e.g. 'twitch'. Must match the value used in Channel.info->provider. */
    public function id(): string;

    /** Human-readable name for UI surfaces. */
    public function displayName(): string;

    /**
     * Does this provider claim the given raw user-entered line (URL, login, slug)?
     * The registry probes providers in registration order; the first match wins.
     * Generic / catch-all providers should return true only as a last resort.
     */
    public function matches(string $rawLine): bool;

    /**
     * Parse the raw line into a normalized MonitoredEntry, or return null if
     * this provider claimed the line via matches() but the line is malformed.
     */
    public function parseEntry(string $rawLine): ?MonitoredEntry;

    /**
     * True when detectLive() can process many entries in a single network call
     * (e.g. Twitch Helix /streams takes up to 100 logins per request).
     * False forces the orchestrator to fan out one detection per entry, which
     * the concurrency pool can still parallelise.
     */
    public function supportsBatchDetection(): bool;

    /**
     * Detect live status for the supplied entries (all of which must belong to
     * this provider. the orchestrator pre-groups by provider id).
     *
     * @param  MonitoredEntry[]  $entries
     * @param  array<string,mixed>  $settings  Plugin settings array.
     * @param  string|null  $cookiesFile  Absolute path to a Netscape cookies file, or null.
     * @return array<string,StreamInfo>  Keyed by MonitoredEntry::key(). MAY omit offline entries.
     */
    public function detectLive(array $entries, array $settings, ?string $cookiesFile): array;

    /** Whether this provider can list past VODs / recordings. */
    public function supportsVods(): bool;

    /**
     * List up to $limit recent VODs for the entry. Empty array when none /
     * unsupported / API failure.
     *
     * @param  array<string,mixed>  $settings
     * @return VodInfo[]
     */
    public function listVods(MonitoredEntry $entry, int $limit, array $settings): array;

    /**
     * Best-effort profile/avatar/logo URL for the entry (used for Channel.logo).
     * Return null when the provider has no logo concept or fetch failed.
     *
     * @param  array<string,mixed>  $settings
     */
    public function fetchLogo(MonitoredEntry $entry, array $settings): ?string;

    /**
     * Default group name for channels created by this provider, e.g. 'Twitch Live'.
     * Orchestrator may override (e.g. by-game grouping for Twitch).
     *
     * @param  array<string,mixed>  $settings
     */
    public function defaultGroupName(array $settings): string;

    /**
     * URL to persist as the Channel.url. m3u-proxy / streamlink will resolve a
     * concrete media stream from this URL on each viewer connection. so it
     * must be a permanent canonical URL (not a short-lived HLS playlist).
     */
    public function streamUrlFor(MonitoredEntry|VodInfo $target): string;
}
