<?php

namespace AppLocalPlugins\Streamarr\Providers\Twitch;

use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around Twitch Helix endpoints used by the plugin.
 *
 * - App Access Token via client_credentials, cached in-memory only.
 * - Batch endpoints chunk to 100 logins per request (Helix hard limit).
 * - Caller MUST call clearToken() at the end of an action handler so we
 *   never persist the token across runs.
 */
class TwitchHelixClient
{
    private ?string $accessToken = null;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    public static function fromSettings(array $settings): ?self
    {
        $clientId = $settings['twitch_client_id'] ?? null;
        $clientSecret = $settings['twitch_client_secret'] ?? null;
        if (! $clientId || ! $clientSecret) {
            return null;
        }

        return new self((string) $clientId, (string) $clientSecret);
    }

    public function clearToken(): void
    {
        $this->accessToken = null;
    }

    public function getAppAccessToken(): ?string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $response = Http::retry(2, 250, throw: false)
            ->timeout(20)
            ->asForm()
            ->post('https://id.twitch.tv/oauth2/token', [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'client_credentials',
            ]);

        if (! $response->successful()) {
            return null;
        }

        return $this->accessToken = $response->json('access_token');
    }

    /**
     * @param  string[]  $logins
     * @return array<string, array{user_id:string,display_name:string,profile_image:string,login:string}>
     */
    public function batchGetUsers(array $logins): array
    {
        if (! $this->getAppAccessToken()) {
            return [];
        }

        $results = [];

        foreach (array_chunk($logins, 100) as $chunk) {
            $query = collect($chunk)->map(fn ($l) => 'login='.urlencode((string) $l))->implode('&');

            $response = $this->request()->get("https://api.twitch.tv/helix/users?{$query}");
            if (! $response->successful()) {
                continue;
            }

            foreach ($response->json('data', []) as $user) {
                $login = strtolower((string) ($user['login'] ?? ''));
                if (! $login) {
                    continue;
                }
                $profileImage = (string) ($user['profile_image_url'] ?? '');
                if ($profileImage !== '') {
                    $profileImage = preg_replace('#-\d+x\d+\.#', '-70x70.', $profileImage);
                }
                $results[$login] = [
                    'user_id' => (string) ($user['id'] ?? ''),
                    'display_name' => (string) ($user['display_name'] ?? $login),
                    'profile_image' => $profileImage,
                    'login' => $login,
                ];
            }
        }

        return $results;
    }

    /**
     * @param  string[]  $logins
     * @return list<array{login:string,display_name:string,user_id:string,title:string,game:string,game_box_art:string,thumbnail:string,language:string,started_at:string,stream_id:string}>
     */
    public function batchGetStreams(array $logins): array
    {
        if (! $this->getAppAccessToken()) {
            return [];
        }

        $results = [];

        foreach (array_chunk($logins, 100) as $chunk) {
            $query = collect($chunk)->map(fn ($l) => 'user_login='.urlencode((string) $l))->implode('&');

            $response = $this->request()->get("https://api.twitch.tv/helix/streams?{$query}");
            if (! $response->successful()) {
                continue;
            }

            foreach ($response->json('data', []) as $stream) {
                if (($stream['type'] ?? '') !== 'live') {
                    continue;
                }
                $login = strtolower((string) ($stream['user_login'] ?? ''));
                $thumbnail = str_replace(['{width}', '{height}'], ['640', '360'], (string) ($stream['thumbnail_url'] ?? ''));
                $gameBoxArt = '';
                $gameId = (string) ($stream['game_id'] ?? '');
                if ($gameId !== '') {
                    $gameBoxArt = "https://static-cdn.jtvnw.net/ttv-boxart/{$gameId}-144x192.jpg";
                }
                $results[] = [
                    'login' => $login,
                    'display_name' => (string) ($stream['user_name'] ?? $login),
                    'user_id' => (string) ($stream['user_id'] ?? ''),
                    'stream_id' => (string) ($stream['id'] ?? ''),
                    'title' => (string) ($stream['title'] ?? ''),
                    'game' => (string) ($stream['game_name'] ?? ''),
                    'game_box_art' => $gameBoxArt,
                    'thumbnail' => $thumbnail,
                    'language' => (string) ($stream['language'] ?? ''),
                    'started_at' => (string) ($stream['started_at'] ?? ''),
                ];
            }
        }

        return $results;
    }

    /** @return list<array<string,mixed>> */
    public function getChannelVideos(string $userId, int $limit = 20): array
    {
        if (! $this->getAppAccessToken()) {
            return [];
        }

        $response = $this->request()->get('https://api.twitch.tv/helix/videos', [
            'user_id' => $userId,
            'type' => 'archive',
            'first' => max(1, min($limit, 100)),
        ]);

        if (! $response->successful()) {
            return [];
        }

        return $response->json('data', []);
    }

    /** @return array<string,mixed>|null */
    public function getVideoById(string $videoId): ?array
    {
        if (! $this->getAppAccessToken()) {
            return null;
        }

        $response = $this->request()->get('https://api.twitch.tv/helix/videos', [
            'id' => $videoId,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $video = $response->json('data.0');

        return is_array($video) ? $video : null;
    }

    private function request()
    {
        return Http::retry(2, 250, throw: false)
            ->timeout(20)
            ->withHeaders([
                'Client-ID' => $this->clientId,
                'Authorization' => "Bearer {$this->accessToken}",
            ]);
    }

    /** Parse Twitch ISO-8601-ish duration like "1h2m3s" into seconds. */
    public static function parseDuration(string $duration): int
    {
        $secs = 0;
        if (preg_match('/(\d+)h/', $duration, $m)) {
            $secs += (int) $m[1] * 3600;
        }
        if (preg_match('/(\d+)m/', $duration, $m)) {
            $secs += (int) $m[1] * 60;
        }
        if (preg_match('/(\d+)s/', $duration, $m)) {
            $secs += (int) $m[1];
        }

        return $secs;
    }
}
