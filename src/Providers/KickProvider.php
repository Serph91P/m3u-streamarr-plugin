<?php

namespace AppLocalPlugins\Streamarr\Providers;

use AppLocalPlugins\Streamarr\Providers\DTO\MonitoredEntry;
use AppLocalPlugins\Streamarr\Providers\DTO\VodInfo;
use Illuminate\Support\Facades\Http;

/**
 * Kick.com live + VOD provider.
 *
 * v1.13.0: Tier-1 provider using the public Kick API
 * (https://kick.com/api/v2/channels/{slug}). VOD listing via
 * .../channels/{slug}/videos. Streamlink fallback is retained for
 * Cloudflare-challenged or otherwise-failed API calls and is exposed
 * via getPendingFallback() so the orchestrator can probe these URLs
 * inline (the same way YouTube and Generic still do).
 */
class KickProvider extends BaseProvider
{
    private const API_BASE = 'https://kick.com/api/v2/channels';

    private const USER_AGENT = 'streamarr/1.13 (m3u-editor plugin)';

    private const REQUEST_TIMEOUT = 10;

    private const PER_CALL_SLEEP_USEC = 150000;

    /** @var string[] URLs whose API call failed during the last detectLive() run. */
    private array $pendingFallback = [];

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
        $line = trim($rawLine);

        return self::isKickUrl($line) || self::isKickShortcut($line);
    }

    public function parseEntry(string $rawLine): ?MonitoredEntry
    {
        $line = trim($rawLine);

        // "kick:slug" shortcut: expand to a canonical channel URL. A bare slug
        // without the "kick:" prefix is intentionally NOT supported because it
        // would collide with Twitch bare logins (backwards compatibility).
        if (self::isKickShortcut($line)) {
            $slug = strtolower(substr($line, 5));
            $url = 'https://kick.com/'.$slug;

            return new MonitoredEntry(
                provider: $this->id(),
                providerId: $url,
                label: $slug,
                rawLine: $line,
                extras: ['slug' => $slug],
            );
        }

        if (! self::isKickUrl($line)) {
            return null;
        }

        $slug = self::extractSlug($line);
        if ($slug === null) {
            return null;
        }

        return new MonitoredEntry(
            provider: $this->id(),
            providerId: $line,
            label: $slug,
            rawLine: $line,
            extras: ['slug' => $slug],
        );
    }

    /**
     * True for the "kick:slug" prefix shortcut.
     */
    private static function isKickShortcut(string $line): bool
    {
        if (! preg_match('/^kick:([A-Za-z0-9_]{1,50})$/', $line, $m)) {
            return false;
        }

        return true;
    }

    public function supportsVods(): bool
    {
        return true;
    }

    /**
     * Detect live status for the supplied Kick entries.
     *
     * Returns an associative array keyed by MonitoredEntry::providerId (the
     * raw URL) whose values are info dicts shaped exactly like
     * checkYouTubeLiveViaStreamlink() returns, so they are interchangeable
     * with the orchestrator's createOrUpdateGenericChannel() consumer.
     *
     * Entries whose API call failed (network error, HTTP non-200, JSON decode
     * error) are recorded in $pendingFallback. The orchestrator should drain
     * that list via getPendingFallback() and run its inline streamlink probe.
     *
     * Entries that returned a valid API response with livestream=null are
     * confirmed offline and appear in neither bucket.
     *
     * @param  MonitoredEntry[]  $entries
     * @param  array<string,mixed>  $settings
     * @return array<string,array{url:string,title:string,author:string,category:string,id:string}>
     */
    public function detectLive(array $entries, array $settings, ?string $cookiesFile): array
    {
        $this->pendingFallback = [];
        $forceFallback = (bool) ($settings['kick_use_streamlink_fallback'] ?? false);
        $results = [];

        foreach ($entries as $i => $entry) {
            if ($entry->provider !== $this->id()) {
                continue;
            }

            $url = $entry->providerId;
            $slug = $entry->extras['slug'] ?? self::extractSlug($url);

            if (! $slug) {
                $this->pendingFallback[] = $url;
                continue;
            }

            if ($forceFallback) {
                $this->pendingFallback[] = $url;
                continue;
            }

            if ($i > 0) {
                usleep(self::PER_CALL_SLEEP_USEC);
            }

            $payload = $this->fetchChannelPayload($slug);
            if ($payload === null) {
                $this->pendingFallback[] = $url;
                continue;
            }

            $live = $payload['livestream'] ?? null;
            if (! is_array($live)) {
                // Confirmed offline. Do not fall back, do not emit.
                continue;
            }

            $results[$url] = $this->shapeStreamInfo($url, $payload, $live);
        }

        return $results;
    }

    /**
     * URLs whose API call failed during the most recent detectLive() invocation.
     *
     * @return string[]
     */
    public function getPendingFallback(): array
    {
        return $this->pendingFallback;
    }

    /**
     * Fetch up to $limit recent VODs for the given entry. Empty on error or
     * when the channel has no public videos.
     *
     * @param  array<string,mixed>  $settings
     * @return VodInfo[]
     */
    public function listVods(MonitoredEntry $entry, int $limit, array $settings): array
    {
        if ($entry->provider !== $this->id() || $limit < 1) {
            return [];
        }

        $slug = $entry->extras['slug'] ?? self::extractSlug($entry->providerId);
        if (! $slug) {
            return [];
        }

        usleep(self::PER_CALL_SLEEP_USEC);

        $url = self::API_BASE.'/'.rawurlencode($slug).'/videos';
        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/json',
            ])->timeout(self::REQUEST_TIMEOUT)->get($url);
        } catch (\Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $videos = $response->json();
        if (! is_array($videos)) {
            return [];
        }

        $out = [];
        foreach ($videos as $vod) {
            if (! is_array($vod)) {
                continue;
            }

            $vodId = (string) ($vod['uuid'] ?? $vod['id'] ?? '');
            if ($vodId === '') {
                continue;
            }

            $title = (string) ($vod['session_title']
                ?? $vod['livestream']['session_title']
                ?? $vod['title']
                ?? 'Kick VOD');

            $sourceUrl = (string) ($vod['source'] ?? '');
            if ($sourceUrl === '') {
                continue;
            }

            $thumb = '';
            if (isset($vod['thumbnail']) && is_array($vod['thumbnail'])) {
                $thumb = (string) ($vod['thumbnail']['src'] ?? $vod['thumbnail']['url'] ?? '');
            } elseif (isset($vod['thumbnail']) && is_string($vod['thumbnail'])) {
                $thumb = $vod['thumbnail'];
            }

            $category = '';
            if (isset($vod['categories'][0]['name'])) {
                $category = (string) $vod['categories'][0]['name'];
            }

            $duration = isset($vod['duration']) ? (int) $vod['duration'] : null;
            // Kick reports duration in milliseconds for VOD payloads.
            if ($duration !== null && $duration > 100000) {
                $duration = (int) round($duration / 1000);
            }

            $out[] = new VodInfo(
                provider: $this->id(),
                vodId: $vodId,
                title: $title !== '' ? $title : 'Kick VOD',
                url: $sourceUrl,
                author: $entry->label,
                category: $category !== '' ? $category : null,
                thumbnailUrl: $thumb !== '' ? $thumb : null,
                durationSeconds: $duration,
                publishedAt: isset($vod['created_at']) ? (string) $vod['created_at'] : null,
                extras: [
                    'slug' => $slug,
                    'views' => (int) ($vod['views'] ?? 0),
                ],
            );

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
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

    /**
     * Pull the Kick channel payload. Returns null on any failure so the
     * orchestrator can decide between streamlink fallback and confirmed
     * offline.
     *
     * @return array<string,mixed>|null
     */
    private function fetchChannelPayload(string $slug): ?array
    {
        $url = self::API_BASE.'/'.rawurlencode($slug);

        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/json',
            ])->timeout(self::REQUEST_TIMEOUT)->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * Convert the Kick API payload into the dict shape that
     * createOrUpdateGenericChannel() consumes.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $live
     * @return array{url:string,title:string,author:string,category:string,id:string}
     */
    private function shapeStreamInfo(string $url, array $payload, array $live): array
    {
        $title = (string) ($live['session_title'] ?? '');
        $author = (string) ($payload['user']['username'] ?? $payload['slug'] ?? '');

        $category = '';
        if (isset($live['categories'][0]['name'])) {
            $category = (string) $live['categories'][0]['name'];
        }

        $id = '';
        if (isset($live['id'])) {
            $id = (string) $live['id'];
        }

        return [
            'url' => $url,
            'title' => $title,
            'author' => $author,
            'category' => $category,
            'id' => $id,
        ];
    }

    private static function isKickUrl(string $line): bool
    {
        return (bool) preg_match('#^https?://(www\.)?kick\.com/#i', $line);
    }

    /**
     * Extract the channel slug (lowercase username) from a Kick URL.
     * Returns null when the URL is not a recognisable channel page.
     */
    private static function extractSlug(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = trim($path, '/');
        if ($path === '') {
            return null;
        }

        $first = explode('/', $path)[0];
        // Drop known non-channel prefixes
        $reserved = ['video', 'videos', 'browse', 'categories', 'search', 'subscriptions', 'following'];
        if (in_array(strtolower($first), $reserved, true)) {
            return null;
        }

        $slug = strtolower($first);
        if (! preg_match('/^[a-z0-9_]{1,50}$/', $slug)) {
            return null;
        }

        return $slug;
    }
}
