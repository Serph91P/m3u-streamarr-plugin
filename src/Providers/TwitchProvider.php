<?php

namespace AppLocalPlugins\Streamarr\Providers;

use AppLocalPlugins\Streamarr\Providers\DTO\MonitoredEntry;
use AppLocalPlugins\Streamarr\Providers\DTO\VodInfo;

/**
 * Twitch.tv provider stub.
 *
 * Phase 1 only carries identity, matching and group-name resolution.
 * Detection (Helix /streams batch + streamlink fallback) and VOD discovery
 * remain inline inside Plugin::handleCheckNow and will move into this class
 * incrementally in later phases.
 */
class TwitchProvider extends BaseProvider
{
    public function id(): string
    {
        return 'twitch';
    }

    public function displayName(): string
    {
        return 'Twitch';
    }

    public function supportsBatchDetection(): bool
    {
        return true;
    }

    public function supportsVods(): bool
    {
        return true;
    }

    public function matches(string $rawLine): bool
    {
        $line = trim($rawLine);
        if ($line === '') {
            return false;
        }

        if (self::isTwitchUrl($line)) {
            return true;
        }

        // Bare login token, e.g. "pokimane" or "nightbot=50".
        $login = $line;
        if (str_contains($login, '=')) {
            $login = explode('=', $login, 2)[0];
        }

        return (bool) preg_match('/^[A-Za-z0-9_]{2,40}$/', $login);
    }

    public function parseEntry(string $rawLine): ?MonitoredEntry
    {
        $line = trim($rawLine);
        if ($line === '') {
            return null;
        }

        $baseNumber = null;
        $login = $line;

        if (self::isTwitchUrl($line)) {
            $path = parse_url($line, PHP_URL_PATH) ?: '';
            $login = trim($path, '/');
            if ($login === '' || str_contains($login, '/')) {
                return null;
            }
        }

        if (str_contains($login, '=')) {
            [$loginPart, $basePart] = explode('=', $login, 2);
            $login = trim($loginPart);
            if (is_numeric($basePart)) {
                $baseNumber = (int) $basePart;
            }
        }

        if (! preg_match('/^[A-Za-z0-9_]{2,40}$/', $login)) {
            return null;
        }

        $login = strtolower($login);

        return new MonitoredEntry(
            provider: $this->id(),
            providerId: $login,
            label: $login,
            rawLine: $line,
            baseNumber: $baseNumber,
        );
    }

    /**
     * Phase 1: handled inline (Helix batch + streamlink fallback) by the
     * orchestrator. This stub returns no live results.
     *
     * @param  MonitoredEntry[]  $entries
     * @param  array<string,mixed>  $settings
     */
    public function detectLive(array $entries, array $settings, ?string $cookiesFile): array
    {
        return [];
    }

    /**
     * Phase 1: VOD discovery is handled inline. Stub returns no VODs.
     *
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
    public function defaultGroupName(array $settings): string
    {
        $group = isset($settings['twitch_group']) ? trim((string) $settings['twitch_group']) : '';

        return $group !== '' ? $group : 'Twitch Live';
    }

    public function streamUrlFor(MonitoredEntry|VodInfo $target): string
    {
        if ($target instanceof VodInfo) {
            return $target->url;
        }

        return 'https://twitch.tv/'.$target->providerId;
    }

    private static function isTwitchUrl(string $line): bool
    {
        return (bool) preg_match('#^https?://(www\.)?twitch\.tv/#i', $line);
    }
}
