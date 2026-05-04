<?php

namespace AppLocalPlugins\Streamarr\Providers;

use AppLocalPlugins\Streamarr\Providers\DTO\MonitoredEntry;
use AppLocalPlugins\Streamarr\Providers\DTO\VodInfo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * YouTube live provider.
 *
 * v1.15.0: Optional YouTube Data API v3 support. When the
 * `youtube_api_key` setting is empty, detectLive() returns no results
 * and pushes every input URL into the pending fallback bucket so the
 * orchestrator falls back to the existing streamlink probe (identical
 * to pre-v1.15 behaviour). When a key is configured, the provider
 * resolves channel handles, runs `search.list` (channelId entries) or
 * `videos.list` (watch?v= entries) and returns dicts shaped like
 * checkYouTubeLiveViaStreamlink() output.
 *
 * Quota notes:
 *  - search.list?eventType=live  : 100 units per call
 *  - videos.list?id=...          : 1 unit per call
 *  - channels.list?forHandle=... : 1 unit per call (handle resolution)
 *
 * Default daily quota of 10k units => about 100 channel checks per day.
 */
class YouTubeProvider extends BaseProvider
{
    private const API_BASE = 'https://www.googleapis.com/youtube/v3';

    private const USER_AGENT = 'streamarr/1.15 (m3u-editor plugin)';

    private const REQUEST_TIMEOUT = 10;

    private const PER_CALL_SLEEP_USEC = 50000;

    /**
     * Hard cap on search.list pages per channel per run. With maxResults=50
     * this covers up to 250 concurrent live broadcasts and bounds quota at
     * 500 units per channel even on pathological inputs.
     */
    private const MAX_LIVE_PAGES = 5;

    /** @var string[] URLs that should be probed via streamlink fallback. */
    private array $pendingFallback = [];

    /** @var array<string,string> Cache of raw URL => resolved channelId for the current run. */
    private array $resolvedChannelId = [];

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
        $line = trim($rawLine);

        return self::isYouTubeUrl($line) || self::isBareHandle($line);
    }

    public function parseEntry(string $rawLine): ?MonitoredEntry
    {
        $line = trim($rawLine);

        // Bare "@Handle" shortcut: expand to a canonical channel URL so the
        // rest of the pipeline (extractHandle, streamlink fallback, etc.)
        // keeps working unchanged.
        if (self::isBareHandle($line)) {
            $handle = ltrim($line, '@');
            $url = 'https://www.youtube.com/@'.$handle;

            return new MonitoredEntry(
                provider: $this->id(),
                providerId: $url,
                label: '@'.$handle,
                rawLine: $line,
            );
        }

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
     * True when the input is a bare "@Handle" shortcut (no whitespace, no
     * slashes). Bare logins without a leading "@" are deliberately NOT
     * treated as YouTube to avoid colliding with Twitch bare logins.
     */
    private static function isBareHandle(string $line): bool
    {
        return (bool) preg_match('/^@[A-Za-z0-9._-]{1,}$/', $line);
    }

    /**
     * Detect live status. Empty API key triggers full streamlink fallback
     * (every URL pushed to pendingFallback, return []).
     *
     * Return shape: each monitored URL maps to a LIST of live broadcasts
     * (one entry per concurrent live video for the URL). Empty list means
     * confirmed offline; absence from the map means the URL was pushed to
     * the streamlink fallback bucket.
     *
     * @param  MonitoredEntry[]  $entries
     * @param  array<string,mixed>  $settings
     * @return array<string,list<array{url:string,title:string,author:string,category:string,id:string}>>
     */
    public function detectLive(array $entries, array $settings, ?string $cookiesFile): array
    {
        $this->pendingFallback = [];
        $this->resolvedChannelId = [];

        $apiKey = trim((string) ($settings['youtube_api_key'] ?? ''));

        // No key configured: keep historical streamlink-only behaviour.
        if ($apiKey === '') {
            foreach ($entries as $entry) {
                if ($entry->provider === $this->id()) {
                    $this->pendingFallback[] = $entry->providerId;
                }
            }

            return [];
        }

        $results = [];
        $apiCallIndex = 0;
        $quotaExhausted = false;
        $keyInvalid = false;

        foreach ($entries as $entry) {
            if ($entry->provider !== $this->id()) {
                continue;
            }

            $url = $entry->providerId;

            // Hard stop on invalid key: drain remaining URLs to fallback.
            if ($keyInvalid) {
                $this->pendingFallback[] = $url;
                continue;
            }

            // Quota exhausted: drain remaining URLs to fallback.
            if ($quotaExhausted) {
                $this->pendingFallback[] = $url;
                continue;
            }

            $videoId = self::extractVideoId($url);
            $channelId = self::extractChannelId($url);
            $handle = self::extractHandle($url);

            if ($videoId !== null) {
                if ($apiCallIndex > 0) {
                    usleep(self::PER_CALL_SLEEP_USEC);
                }
                $apiCallIndex++;

                [$info, $error] = $this->fetchVideoLive($apiKey, $videoId, $url);
                if ($error === 'quotaExceeded') {
                    $quotaExhausted = true;
                    Log::warning('streamarr: YouTube Data API quota exceeded; falling back to streamlink for remaining URLs.');
                    $this->pendingFallback[] = $url;
                    continue;
                }
                if ($error === 'keyInvalid') {
                    $keyInvalid = true;
                    Log::warning('streamarr: YouTube Data API key rejected (keyInvalid); falling back to streamlink for all URLs this run.');
                    // Drain everything we collected so far back into fallback.
                    foreach ($results as $u => $_) {
                        $this->pendingFallback[] = $u;
                    }
                    $results = [];
                    $this->pendingFallback[] = $url;
                    continue;
                }
                if ($error !== null) {
                    $this->pendingFallback[] = $url;
                    continue;
                }
                if ($info !== null) {
                    $results[$url][] = $info;
                }
                // info === null and no error => confirmed offline (do not emit, do not fall back).
                continue;
            }

            if ($channelId === null && $handle !== null) {
                if ($apiCallIndex > 0) {
                    usleep(self::PER_CALL_SLEEP_USEC);
                }
                $apiCallIndex++;

                [$resolved, $error] = $this->resolveHandleToChannelId($apiKey, $handle, $url);
                if ($error === 'quotaExceeded') {
                    $quotaExhausted = true;
                    Log::warning('streamarr: YouTube Data API quota exceeded; falling back to streamlink for remaining URLs.');
                    $this->pendingFallback[] = $url;
                    continue;
                }
                if ($error === 'keyInvalid') {
                    $keyInvalid = true;
                    Log::warning('streamarr: YouTube Data API key rejected (keyInvalid); falling back to streamlink for all URLs this run.');
                    foreach ($results as $u => $_) {
                        $this->pendingFallback[] = $u;
                    }
                    $results = [];
                    $this->pendingFallback[] = $url;
                    continue;
                }
                if ($error !== null || $resolved === null) {
                    $this->pendingFallback[] = $url;
                    continue;
                }
                $channelId = $resolved;
                $this->resolvedChannelId[$url] = $resolved;
            }

            if ($channelId === null) {
                // Unresolvable (e.g. legacy /c/ paths): hand over to streamlink.
                $this->pendingFallback[] = $url;
                continue;
            }

            if ($apiCallIndex > 0) {
                usleep(self::PER_CALL_SLEEP_USEC);
            }
            $apiCallIndex++;

            [$infos, $error] = $this->fetchLiveSearch($apiKey, $channelId, $url);
            if ($error === 'quotaExceeded') {
                $quotaExhausted = true;
                Log::warning('streamarr: YouTube Data API quota exceeded; falling back to streamlink for remaining URLs.');
                $this->pendingFallback[] = $url;
                continue;
            }
            if ($error === 'keyInvalid') {
                $keyInvalid = true;
                Log::warning('streamarr: YouTube Data API key rejected (keyInvalid); falling back to streamlink for all URLs this run.');
                foreach ($results as $u => $_) {
                    $this->pendingFallback[] = $u;
                }
                $results = [];
                $this->pendingFallback[] = $url;
                continue;
            }
            if ($error !== null) {
                $this->pendingFallback[] = $url;
                continue;
            }
            foreach ($infos as $info) {
                $results[$url][] = $info;
            }
            // empty $infos => confirmed offline.
        }

        return $results;
    }

    /**
     * URLs that need streamlink fallback after the most recent detectLive() run.
     *
     * @return string[]
     */
    public function getPendingFallback(): array
    {
        return $this->pendingFallback;
    }

    public function clearPendingFallback(): void
    {
        $this->pendingFallback = [];
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

    // ---------------------------------------------------------------------
    // API calls
    // ---------------------------------------------------------------------

    /**
     * videos.list?part=liveStreamingDetails,snippet&id={vid}  (1 unit)
     *
     * @return array{0: ?array{url:string,title:string,author:string,category:string,id:string}, 1: ?string}
     *               [info, error] where error is one of: null, 'quotaExceeded', 'keyInvalid', 'other'.
     */
    private function fetchVideoLive(string $apiKey, string $videoId, string $url): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/json',
            ])->timeout(self::REQUEST_TIMEOUT)->get(self::API_BASE.'/videos', [
                'part' => 'liveStreamingDetails,snippet',
                'id' => $videoId,
                'key' => $apiKey,
            ]);
        } catch (\Throwable) {
            return [null, 'other'];
        }

        $err = $this->classifyError($response);
        if ($err !== null) {
            return [null, $err];
        }

        $json = $response->json();
        if (! is_array($json) || empty($json['items']) || ! is_array($json['items'][0])) {
            return [null, null]; // Confirmed offline / not found.
        }

        $item = $json['items'][0];
        $live = $item['liveStreamingDetails'] ?? null;
        $snippet = $item['snippet'] ?? [];

        // Distinguish live vs scheduled vs ended:
        //   actualStartTime + no actualEndTime  => live now
        //   broadcastContent in snippet === 'live' is also a strong hint.
        $broadcast = (string) ($snippet['liveBroadcastContent'] ?? 'none');
        $isLive = false;
        if (is_array($live)) {
            $hasStart = ! empty($live['actualStartTime']);
            $hasEnd = ! empty($live['actualEndTime']);
            $isLive = $hasStart && ! $hasEnd;
        }
        if (! $isLive && $broadcast === 'live') {
            $isLive = true;
        }
        if (! $isLive) {
            return [null, null];
        }

        return [[
            'url' => $url,
            'title' => (string) ($snippet['title'] ?? ''),
            'author' => (string) ($snippet['channelTitle'] ?? ''),
            'category' => '',
            'id' => $videoId,
        ], null];
    }

    /**
     * search.list?part=snippet&channelId={id}&eventType=live&type=video  (100 units per page)
     *
     * Returns ALL concurrently live broadcasts for the channel. Uses
     * maxResults=50 (the API maximum) and follows nextPageToken up to a
     * hard cap of MAX_LIVE_PAGES pages. Channels like 24/7 lofi radios
     * routinely run 10-20 simultaneous broadcasts; the previous cap of 10
     * silently dropped the rest.
     *
     * Quota note: search.list costs 100 units per page regardless of
     * maxResults, so the per-page widening is free. Pagination only kicks
     * in when totalResults exceeds 50, which is rare.
     *
     * @return array{0: list<array{url:string,title:string,author:string,category:string,id:string}>, 1: ?string}
     */
    private function fetchLiveSearch(string $apiKey, string $channelId, string $url): array
    {
        $infos = [];
        $seenVideoIds = [];
        $pageToken = null;
        $pages = 0;

        do {
            $params = [
                'part' => 'snippet',
                'channelId' => $channelId,
                'eventType' => 'live',
                'type' => 'video',
                'maxResults' => 50,
                'key' => $apiKey,
            ];
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            try {
                $response = Http::withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'application/json',
                ])->timeout(self::REQUEST_TIMEOUT)->get(self::API_BASE.'/search', $params);
            } catch (\Throwable) {
                return [$infos, $infos === [] ? 'other' : null];
            }

            $err = $this->classifyError($response);
            if ($err !== null) {
                return [$infos, $infos === [] ? $err : null];
            }

            $json = $response->json();
            if (! is_array($json) || empty($json['items']) || ! is_array($json['items'])) {
                // First page empty => confirmed offline. Subsequent empty
                // page just means we have everything already.
                return [$infos, null];
            }

            foreach ($json['items'] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $snippet = $item['snippet'] ?? [];
                $vid = (string) ($item['id']['videoId'] ?? '');
                if ($vid === '' || isset($seenVideoIds[$vid])) {
                    continue;
                }
                $seenVideoIds[$vid] = true;
                $infos[] = [
                    // Point at the actual watch URL so streamlink/m3u-proxy can
                    // play this specific broadcast directly.
                    'url' => 'https://www.youtube.com/watch?v='.$vid,
                    'title' => (string) ($snippet['title'] ?? ''),
                    'author' => (string) ($snippet['channelTitle'] ?? ''),
                    'category' => '',
                    'id' => $vid,
                ];
            }

            $pageToken = isset($json['nextPageToken']) ? (string) $json['nextPageToken'] : null;
            $pages++;
        } while ($pageToken !== null && $pages < self::MAX_LIVE_PAGES);

        return [$infos, null];
    }

    /**
     * channels.list?part=id&forHandle=@Handle (1 unit).
     *
     * @return array{0: ?string, 1: ?string} [channelId, error]
     */
    private function resolveHandleToChannelId(string $apiKey, string $handle, string $url): array
    {
        if (isset($this->resolvedChannelId[$url])) {
            return [$this->resolvedChannelId[$url], null];
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/json',
            ])->timeout(self::REQUEST_TIMEOUT)->get(self::API_BASE.'/channels', [
                'part' => 'id',
                'forHandle' => $handle,
                'key' => $apiKey,
            ]);
        } catch (\Throwable) {
            return [null, 'other'];
        }

        $err = $this->classifyError($response);
        if ($err !== null) {
            return [null, $err];
        }

        $json = $response->json();
        if (! is_array($json) || empty($json['items']) || ! is_array($json['items'][0])) {
            return [null, 'other'];
        }

        $cid = (string) ($json['items'][0]['id'] ?? '');
        if ($cid === '') {
            return [null, 'other'];
        }

        return [$cid, null];
    }

    /**
     * Classify an HTTP error response into 'quotaExceeded' | 'keyInvalid' | 'other' | null.
     */
    private function classifyError(\Illuminate\Http\Client\Response $response): ?string
    {
        if ($response->successful()) {
            return null;
        }

        $status = $response->status();
        $body = $response->json();
        $reason = '';
        if (is_array($body) && isset($body['error']['errors'][0]['reason'])) {
            $reason = (string) $body['error']['errors'][0]['reason'];
        }

        if ($status === 403 && $reason === 'quotaExceeded') {
            return 'quotaExceeded';
        }
        if ($status === 400 && in_array($reason, ['keyInvalid', 'badRequest'], true)) {
            // Treat keyInvalid explicitly; badRequest with no reason should be 'other'.
            if ($reason === 'keyInvalid') {
                return 'keyInvalid';
            }
        }

        return 'other';
    }

    // ---------------------------------------------------------------------
    // URL parsing
    // ---------------------------------------------------------------------

    private static function isYouTubeUrl(string $line): bool
    {
        return (bool) preg_match('#^https?://(www\.)?(youtube\.com|youtu\.be)/#i', $line);
    }

    private static function extractVideoId(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if ($host === 'youtu.be' || $host === 'www.youtu.be') {
            $vid = trim($path, '/');
            if ($vid !== '' && preg_match('/^[A-Za-z0-9_-]{6,}$/', $vid)) {
                return $vid;
            }

            return null;
        }

        // youtube.com/watch?v=VIDEOID
        if (preg_match('#/watch$#i', $path) || preg_match('#/watch/#i', $path)) {
            parse_str((string) ($parts['query'] ?? ''), $q);
            $vid = (string) ($q['v'] ?? '');
            if ($vid !== '' && preg_match('/^[A-Za-z0-9_-]{6,}$/', $vid)) {
                return $vid;
            }
        }

        // youtube.com/live/VIDEOID
        if (preg_match('#^/live/([A-Za-z0-9_-]{6,})#i', $path, $m)) {
            return $m[1];
        }

        return null;
    }

    private static function extractChannelId(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('#^/channel/(UC[A-Za-z0-9_-]{10,})#', $path, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Returns the handle including the leading '@' (YouTube API expects it).
     */
    private static function extractHandle(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('#^/(@[A-Za-z0-9._-]{1,})#', $path, $m)) {
            return $m[1];
        }

        return null;
    }
}
