<?php

namespace AppLocalPlugins\Streamarr\Providers\Twitch;

use AppLocalPlugins\Streamarr\Providers\DTO\MonitoredEntry;
use AppLocalPlugins\Streamarr\Providers\DTO\StreamInfo;
use AppLocalPlugins\Streamarr\Providers\DTO\VodInfo;
use AppLocalPlugins\Streamarr\Providers\PlatformProvider;
use AppLocalPlugins\Streamarr\Streamlink\StreamlinkRunner;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Twitch provider. Helix API when credentials are configured, otherwise
 * falls back to `streamlink --json` against the channel URL (slower, no batch,
 * no metadata enrichment).
 *
 * Accepted line formats (from `monitored_channels` textarea):
 *   - bare login:        "shroud"
 *   - login w/ number:   "shroud=42"
 *   - channel URL:       "https://www.twitch.tv/shroud"
 *   - VOD URL:           "https://www.twitch.tv/videos/123456789"
 *
 * NOT claimed by this provider:
 *   - youtube.com / youtu.be URLs
 *   - kick.com URLs
 *   - any URL with a known different-platform host
 */
class TwitchProvider implements PlatformProvider
{
    /** Hosts known to belong to other providers. never claim them as Twitch. */
    private const FOREIGN_HOSTS = [
        'youtube.com', 'youtu.be', 'kick.com', 'rumble.com', 'dlive.tv',
        'vimeo.com', 'bilibili.com', 'nicovideo.jp', 'huya.com', 'douyu.com',
        'afreecatv.com', 'soop.live',
    ];

    public function __construct(
        private readonly StreamlinkRunner $streamlink,
    ) {
    }

    public function id(): string
    {
        return 'twitch';
    }

    public function displayName(): string
    {
        return 'Twitch';
    }

    public function matches(string $rawLine): bool
    {
        $line = trim($rawLine);
        if ($line === '') {
            return false;
        }

        // Explicit twitch URL
        if (stripos($line, 'twitch.tv') !== false) {
            return true;
        }

        // Bare slug / login (no URL). but not when it looks like a foreign host
        if (preg_match('#^https?://#i', $line)) {
            $host = strtolower((string) parse_url($line, PHP_URL_HOST));
            foreach (self::FOREIGN_HOSTS as $foreign) {
                if ($host === $foreign || str_ends_with($host, '.'.$foreign)) {
                    return false;
                }
            }
            // Unknown URL. leave for a later provider to claim.
            return false;
        }

        // bare token like "shroud" or "shroud=42"
        return (bool) preg_match('/^[\w.-]+(?:=\d+)?$/', $line);
    }

    public function parseEntry(string $rawLine): ?MonitoredEntry
    {
        $line = trim($rawLine);
        $baseNumber = null;

        // VOD URL
        if (preg_match('#twitch\.tv/videos/(\d+)#', $line, $m)) {
            $videoId = $m[1];

            return new MonitoredEntry(
                provider: $this->id(),
                providerId: 'vod:'.$videoId,
                label: "Twitch VOD {$videoId}",
                rawLine: $rawLine,
                extras: ['kind' => 'vod', 'video_id' => $videoId],
            );
        }

        // Channel URL
        if (preg_match('#twitch\.tv/([\w.-]+)#', $line, $m)) {
            $login = strtolower($m[1]);
            if (in_array($login, ['directory', 'videos', 'settings', 'subscriptions', 'inventory', 'wallet'], true)) {
                return null;
            }

            return new MonitoredEntry(
                provider: $this->id(),
                providerId: $login,
                label: $login,
                rawLine: $rawLine,
                extras: ['kind' => 'channel'],
            );
        }

        // bare token (with optional =N)
        if (preg_match('/^([\w.-]+)(?:=(\d+))?$/', $line, $m)) {
            $login = strtolower($m[1]);
            $baseNumber = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;

            return new MonitoredEntry(
                provider: $this->id(),
                providerId: $login,
                label: $login,
                rawLine: $rawLine,
                baseNumber: $baseNumber,
                extras: ['kind' => 'channel'],
            );
        }

        return null;
    }

    public function supportsBatchDetection(): bool
    {
        // True only when API credentials are configured. The orchestrator queries
        // capabilities per-call via batchAvailable(). see helper below.
        return true;
    }

    public function detectLive(array $entries, array $settings, ?string $cookiesFile): array
    {
        if (empty($entries)) {
            return [];
        }

        $client = TwitchHelixClient::fromSettings($settings);

        if ($client) {
            try {
                return $this->detectViaHelix($entries, $client);
            } finally {
                $client->clearToken();
            }
        }

        return $this->detectViaStreamlink($entries, $cookiesFile);
    }

    /**
     * @param  MonitoredEntry[]  $entries
     * @return array<string,StreamInfo>
     */
    private function detectViaHelix(array $entries, TwitchHelixClient $client): array
    {
        $channelLogins = [];
        foreach ($entries as $entry) {
            if (($entry->extras['kind'] ?? 'channel') === 'channel') {
                $channelLogins[] = $entry->providerId;
            }
        }
        $channelLogins = array_values(array_unique($channelLogins));

        $users = $client->batchGetUsers($channelLogins);
        $streams = $client->batchGetStreams($channelLogins);

        $streamByLogin = [];
        foreach ($streams as $s) {
            $streamByLogin[$s['login']] = $s;
        }

        $out = [];

        foreach ($entries as $entry) {
            $kind = $entry->extras['kind'] ?? 'channel';

            if ($kind === 'vod') {
                // VOD entries are not "live", but we still emit a snapshot so the
                // orchestrator can persist the channel row.
                $videoId = (string) $entry->extras['video_id'];
                $video = $client->getVideoById($videoId);
                if (! $video) {
                    continue;
                }
                $out[$entry->key()] = new StreamInfo(
                    entryKey: $entry->key(),
                    isLive: false,
                    title: (string) ($video['title'] ?? "Twitch VOD {$videoId}"),
                    author: (string) ($video['user_name'] ?? null),
                    thumbnailUrl: str_replace(['%{width}', '%{height}'], ['640', '360'], (string) ($video['thumbnail_url'] ?? '')),
                    streamUrl: (string) ($video['url'] ?? "https://www.twitch.tv/videos/{$videoId}"),
                    streamId: $videoId,
                    extras: [
                        'kind' => 'vod',
                        'video_id' => $videoId,
                        'duration_secs' => TwitchHelixClient::parseDuration((string) ($video['duration'] ?? '')),
                    ],
                );

                continue;
            }

            $login = $entry->providerId;
            $user = $users[$login] ?? null;
            $stream = $streamByLogin[$login] ?? null;

            if (! $stream) {
                $out[$entry->key()] = new StreamInfo(
                    entryKey: $entry->key(),
                    isLive: false,
                    author: $user['display_name'] ?? $login,
                    extras: [
                        'profile_image' => $user['profile_image'] ?? null,
                        'user_id' => $user['user_id'] ?? null,
                    ],
                );

                continue;
            }

            $out[$entry->key()] = new StreamInfo(
                entryKey: $entry->key(),
                isLive: true,
                title: $stream['title'],
                category: $stream['game'],
                author: $stream['display_name'],
                thumbnailUrl: $stream['thumbnail'],
                streamUrl: "https://www.twitch.tv/{$login}",
                streamId: $stream['stream_id'],
                startedAt: $stream['started_at'] ?: Carbon::now()->toISOString(),
                extras: [
                    'login' => $login,
                    'user_id' => $stream['user_id'],
                    'game_box_art' => $stream['game_box_art'],
                    'profile_image' => $user['profile_image'] ?? null,
                    'language' => $stream['language'],
                ],
            );
        }

        return $out;
    }

    /**
     * @param  MonitoredEntry[]  $entries
     * @return array<string,StreamInfo>
     */
    private function detectViaStreamlink(array $entries, ?string $cookiesFile): array
    {
        $out = [];
        $binary = $this->streamlink->findBinary();
        if (! $binary) {
            return $out;
        }

        foreach ($entries as $entry) {
            $kind = $entry->extras['kind'] ?? 'channel';
            if ($kind !== 'channel') {
                // Without Helix we can't enrich VODs.
                continue;
            }
            $login = $entry->providerId;
            $detected = $this->streamlink->detectLive("https://www.twitch.tv/{$login}", $cookiesFile);

            if (! $detected) {
                $out[$entry->key()] = new StreamInfo(entryKey: $entry->key(), isLive: false, author: $login);

                continue;
            }

            $out[$entry->key()] = new StreamInfo(
                entryKey: $entry->key(),
                isLive: true,
                title: $detected['title'] !== '' ? $detected['title'] : "{$login} - Live",
                category: $detected['category'],
                author: $detected['author'] ?? $login,
                streamUrl: "https://www.twitch.tv/{$login}",
                startedAt: Carbon::now()->toISOString(),
                extras: ['login' => $login],
            );
        }

        return $out;
    }

    public function supportsVods(): bool
    {
        return true;
    }

    public function listVods(MonitoredEntry $entry, int $limit, array $settings): array
    {
        $client = TwitchHelixClient::fromSettings($settings);
        if (! $client) {
            return [];
        }

        try {
            $kind = $entry->extras['kind'] ?? 'channel';
            if ($kind === 'vod') {
                $video = $client->getVideoById((string) $entry->extras['video_id']);
                if (! $video) {
                    return [];
                }

                return [$this->mapVideo($video)];
            }

            // channel: need user_id first
            $users = $client->batchGetUsers([$entry->providerId]);
            $user = $users[$entry->providerId] ?? null;
            if (! $user || $user['user_id'] === '') {
                return [];
            }

            $videos = $client->getChannelVideos($user['user_id'], $limit);

            return array_map(fn ($v) => $this->mapVideo($v), $videos);
        } finally {
            $client->clearToken();
        }
    }

    private function mapVideo(array $video): VodInfo
    {
        $durationSecs = TwitchHelixClient::parseDuration((string) ($video['duration'] ?? ''));
        $thumbnail = str_replace(['%{width}', '%{height}'], ['640', '360'], (string) ($video['thumbnail_url'] ?? ''));

        return new VodInfo(
            provider: $this->id(),
            vodId: (string) ($video['id'] ?? ''),
            title: (string) ($video['title'] ?? 'Untitled VOD'),
            url: (string) ($video['url'] ?? ''),
            author: (string) ($video['user_name'] ?? null),
            thumbnailUrl: $thumbnail !== '' ? $thumbnail : null,
            durationSeconds: $durationSecs ?: null,
            publishedAt: (string) ($video['published_at'] ?? null),
            extras: [
                'description' => (string) ($video['description'] ?? ''),
                'view_count' => (int) ($video['view_count'] ?? 0),
                'language' => (string) ($video['language'] ?? ''),
                'vod_type' => (string) ($video['type'] ?? 'archive'),
                'duration_secs' => $durationSecs,
                'stream_id' => (string) ($video['stream_id'] ?? ''),
                'muted_segments' => array_map(
                    fn (array $seg) => ['duration' => (int) ($seg['duration'] ?? 0), 'offset' => (int) ($seg['offset'] ?? 0)],
                    (array) ($video['muted_segments'] ?? []),
                ),
            ],
        );
    }

    public function fetchLogo(MonitoredEntry $entry, array $settings): ?string
    {
        $client = TwitchHelixClient::fromSettings($settings);
        if ($client && ($entry->extras['kind'] ?? 'channel') === 'channel') {
            try {
                $users = $client->batchGetUsers([$entry->providerId]);

                return $users[$entry->providerId]['profile_image'] ?? null;
            } finally {
                $client->clearToken();
            }
        }

        // Unauthenticated decapi.me fallback (same as legacy code path).
        $response = Http::timeout(10)->get("https://decapi.me/twitch/avatar/{$entry->providerId}");
        if ($response->successful()) {
            $body = trim($response->body());
            if ($body !== '' && filter_var($body, FILTER_VALIDATE_URL)) {
                return $body;
            }
        }

        return null;
    }

    public function defaultGroupName(array $settings): string
    {
        return (string) ($settings['twitch_group_name'] ?? 'Twitch Live');
    }

    public function streamUrlFor(MonitoredEntry|VodInfo $target): string
    {
        if ($target instanceof VodInfo) {
            return $target->url;
        }
        $kind = $target->extras['kind'] ?? 'channel';
        if ($kind === 'vod') {
            return 'https://www.twitch.tv/videos/'.$target->extras['video_id'];
        }

        return 'https://www.twitch.tv/'.$target->providerId;
    }
}
