<?php

namespace AppLocalPlugins\Streamarr\Providers\YouTube;

use AppLocalPlugins\Streamarr\Providers\DTO\MonitoredEntry;
use AppLocalPlugins\Streamarr\Providers\DTO\StreamInfo;
use AppLocalPlugins\Streamarr\Providers\DTO\VodInfo;
use AppLocalPlugins\Streamarr\Providers\PlatformProvider;
use AppLocalPlugins\Streamarr\Streamlink\StreamlinkRunner;
use Carbon\Carbon;

/**
 * YouTube provider. detects live streams by probing
 * `streamlink --json <url>`. There is no batch API; detectLive() loops one
 * URL at a time. The orchestrator's concurrency pool fans these out.
 *
 * Accepted line formats:
 *   - https://www.youtube.com/@handle           → normalised to ./@handle/live
 *   - https://www.youtube.com/channel/UCxxx     → normalised to ./channel/UCxxx/live
 *   - https://www.youtube.com/c/slug            → normalised to ./c/slug/live
 *   - https://www.youtube.com/watch?v=VIDEOID
 *   - https://youtu.be/VIDEOID
 *   - URLs already ending in /live
 *
 * VOD listing is not implemented (would require Data API key + quota); this
 * provider focuses on live monitoring, which is what the legacy code did.
 */
class YouTubeProvider implements PlatformProvider
{
    public function __construct(
        private readonly StreamlinkRunner $streamlink,
    ) {
    }

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
        return (bool) preg_match('#^https?://(www\.)?(youtube\.com|youtu\.be)/#i', trim($rawLine));
    }

    public function parseEntry(string $rawLine): ?MonitoredEntry
    {
        $url = $this->normalizeUrl(trim($rawLine));
        if ($url === '') {
            return null;
        }

        $label = $this->labelFromUrl($url);

        return new MonitoredEntry(
            provider: $this->id(),
            providerId: $url,        // URL is the stable id
            label: $label,
            rawLine: $rawLine,
            extras: ['normalized_url' => $url],
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

        foreach ($entries as $entry) {
            $url = $entry->extras['normalized_url'] ?? $entry->providerId;
            $detected = $this->streamlink->detectLive($url, $cookiesFile);

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
                title: $detected['title'] !== '' ? $detected['title'] : ($detected['author'] ?? $entry->label).' - Live',
                category: $detected['category'],
                author: $detected['author'] ?? $entry->label,
                streamUrl: $url,
                streamId: $detected['id'],
                startedAt: Carbon::now()->toISOString(),
                extras: ['url' => $url],
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
        // YouTube avatar fetch requires Data API or HTML scraping; legacy code
        // didn't do it either, so we return null and let the orchestrator fall
        // back to the m3u-editor default channel image.
        return null;
    }

    public function defaultGroupName(array $settings): string
    {
        return (string) ($settings['youtube_group_name'] ?? 'YouTube Live');
    }

    public function streamUrlFor(MonitoredEntry|VodInfo $target): string
    {
        if ($target instanceof VodInfo) {
            return $target->url;
        }

        return $target->extras['normalized_url'] ?? $target->providerId;
    }

    public function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        // Already pointing at a live page or specific video. leave alone.
        if (str_contains($url, '/live') || str_contains($url, 'watch?v=') || str_contains($url, 'youtu.be/')) {
            return $url;
        }

        return rtrim($url, '/').'/live';
    }

    private function labelFromUrl(string $url): string
    {
        if (preg_match('#youtube\.com/@([\w.-]+)#i', $url, $m)) {
            return '@'.$m[1];
        }
        if (preg_match('#youtube\.com/(?:channel|c|user)/([\w.-]+)#i', $url, $m)) {
            return $m[1];
        }
        if (preg_match('#youtu\.be/([\w-]+)#i', $url, $m)) {
            return 'YouTube '.$m[1];
        }
        if (preg_match('#watch\?v=([\w-]+)#i', $url, $m)) {
            return 'YouTube '.$m[1];
        }

        return 'YouTube';
    }
}
