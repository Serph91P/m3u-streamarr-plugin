<?php

namespace AppLocalPlugins\Streamarr\Providers\Generic;

use AppLocalPlugins\Streamarr\Providers\DTO\MonitoredEntry;
use AppLocalPlugins\Streamarr\Providers\DTO\StreamInfo;
use AppLocalPlugins\Streamarr\Providers\DTO\VodInfo;
use AppLocalPlugins\Streamarr\Providers\PlatformProvider;
use AppLocalPlugins\Streamarr\Streamlink\StreamlinkRunner;
use Carbon\Carbon;

/**
 * Generic streamlink catch-all.
 *
 * Claims any http(s) URL that no earlier provider matched. meaning streamlink
 * is asked to handle it. This unlocks the long-tail of platforms streamlink
 * supports out of the box: Kick, BiliBili, NicoNico, Vimeo, Rumble, AfreecaTV,
 * SOOP, DLive, Mildom, etc.
 *
 * Behaviour:
 *  - matches(): true for every http(s) URL (registered LAST in registry, so
 *    Twitch / YouTube get first dibs).
 *  - parseEntry(): URL is the stable id; label derived from host + path tail.
 *  - detectLive(): one `streamlink --json` call per entry (no batching).
 *  - VODs: not supported (no platform-agnostic listing endpoint exists).
 *  - Logo: not supported (no platform-agnostic favicon strategy).
 *
 * Per-host plugin args can be set on the streamlink command line via the
 * settings key `generic_streamlink_extra_args` (one arg per line). this is
 * where users put e.g. `--niconico-email=.`, `--bilibili-cookies=.`, etc.
 * Cookie file (set globally on the StreamProfile) still applies.
 */
class GenericStreamlinkProvider implements PlatformProvider
{
    public function __construct(
        private readonly StreamlinkRunner $streamlink,
    ) {
    }

    public function id(): string
    {
        return 'streamlink';
    }

    public function displayName(): string
    {
        return 'Generic streamlink URL';
    }

    public function matches(string $rawLine): bool
    {
        return (bool) preg_match('#^https?://[^\s]+$#i', trim($rawLine));
    }

    public function parseEntry(string $rawLine): ?MonitoredEntry
    {
        $url = trim($rawLine);
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        $tail = trim((string) preg_replace('#/+#', '/', $path), '/');
        $label = $tail !== '' ? "{$host}/{$tail}" : $host;

        return new MonitoredEntry(
            provider: $this->id(),
            providerId: $url,
            label: $label,
            rawLine: $rawLine,
            extras: ['host' => $host],
        );
    }

    public function supportsBatchDetection(): bool
    {
        return false;
    }

    public function detectLive(array $entries, array $settings, ?string $cookiesFile): array
    {
        $out = [];
        $binary = $this->streamlink->findBinary();
        if (! $binary) {
            return $out;
        }

        $extraArgs = $this->extraArgs($settings);

        foreach ($entries as $entry) {
            $url = $entry->providerId;
            $detected = $this->streamlink->detectLive($url, $cookiesFile, $extraArgs);

            if (! $detected) {
                $out[$entry->key()] = new StreamInfo(
                    entryKey: $entry->key(),
                    isLive: false,
                    author: $entry->label,
                );

                continue;
            }

            $out[$entry->key()] = new StreamInfo(
                entryKey: $entry->key(),
                isLive: true,
                title: $detected['title'] !== '' ? $detected['title'] : $entry->label.' - Live',
                category: $detected['category'],
                author: $detected['author'] ?? $entry->label,
                streamUrl: $url,
                streamId: $detected['id'],
                startedAt: Carbon::now()->toISOString(),
                extras: ['host' => $entry->extras['host'] ?? null, 'url' => $url],
            );
        }

        return $out;
    }

    public function supportsVods(): bool
    {
        return false;
    }

    public function listVods(MonitoredEntry $entry, int $limit, array $settings): array
    {
        return [];
    }

    public function fetchLogo(MonitoredEntry $entry, array $settings): ?string
    {
        return null;
    }

    public function defaultGroupName(array $settings): string
    {
        return (string) ($settings['streamlink_group_name'] ?? 'Live Streams');
    }

    public function streamUrlFor(MonitoredEntry|VodInfo $target): string
    {
        if ($target instanceof VodInfo) {
            return $target->url;
        }

        return $target->providerId;
    }

    /**
     * @return string[]
     */
    private function extraArgs(array $settings): array
    {
        $raw = $settings['generic_streamlink_extra_args'] ?? '';
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $args = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $args[] = $line;
        }

        return $args;
    }
}
