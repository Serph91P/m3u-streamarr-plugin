<?php

namespace AppLocalPlugins\Streamarr;

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Enums\EpgSourceType;
use App\Enums\Status;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Group;
use App\Models\StreamProfile;
use App\Plugins\Contracts\ChannelProcessorPluginInterface;
use App\Plugins\Contracts\EpgProcessorPluginInterface;
use App\Plugins\Contracts\PluginInterface;
use App\Plugins\Contracts\ScheduledPluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Tags\Tag;

class Plugin implements ChannelProcessorPluginInterface, EpgProcessorPluginInterface, PluginInterface, ScheduledPluginInterface
{
    private const PLUGIN_MARKER = 'streamarr';

    private const STREAM_TYPE_LIVE = 'live';

    private const STREAM_TYPE_VOD = 'vod';

    /** @var string|null Cached Twitch App Access Token for current action run */
    private ?string $accessToken = null;

    /** @var Epg|null Cached EPG source for current action run */
    private ?Epg $epgSource = null;

    /** @var string EPG mode for current action run: 'game' or 'title' */
    private string $epgMode = 'game';

    private ?bool $groupsEnabledColumnExists = null;

    private ?bool $groupsSortOrderColumnExists = null;

    /**
     * Plugin-internal source files have already been wired in once per
     * PHP process. re-wiring is a no-op thanks to require_once, but this
     * flag avoids hitting the filesystem on every Plugin instantiation.
     */
    private static bool $srcLoaded = false;

    public function __construct()
    {
        $this->bootSrcFiles();
    }

    /**
     * The m3u-editor host only `require_once`s the entrypoint file declared in
     * plugin.json. Additional source files under `src/` are part of the
     * integrity-hashed plugin payload but must be wired in by the plugin
     * itself. Each file is a pure class declaration with no side effects.
     */
    private function bootSrcFiles(): void
    {
        if (self::$srcLoaded) {
            return;
        }

        $base = __DIR__.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR;
        $files = [
            'Providers/DTO/MonitoredEntry.php',
            'Providers/DTO/StreamInfo.php',
            'Providers/DTO/VodInfo.php',
            'Providers/PlatformProvider.php',
            'Providers/ProviderRegistry.php',
            'Providers/BaseProvider.php',
            'Providers/TwitchProvider.php',
            'Providers/YouTubeProvider.php',
            'Providers/KickProvider.php',
            'Providers/GenericProvider.php',
        ];
        foreach ($files as $relative) {
            $path = $base.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (is_file($path)) {
                require_once $path;
            }
        }

        self::$srcLoaded = true;
    }

    // -------------------------------------------------------------------------
    // PluginInterface
    // -------------------------------------------------------------------------

    public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return match ($action) {
            'check_now' => $this->handleCheckNow($context),
            'repair_xtream' => $this->handleRepairXtream($context),
            'add_manual' => $this->handleAddManual($payload, $context),
            'cleanup' => $this->handleCleanup($context),
            'reset_all' => $this->handleResetAll($context),
            'test_url' => $this->handleTestUrl($payload, $context),
            default => PluginActionResult::failure("Unknown action: {$action}"),
        };
    }

    // -------------------------------------------------------------------------
    // ScheduledPluginInterface
    // -------------------------------------------------------------------------

    public function scheduledActions(CarbonInterface $now, array $settings): array
    {
        if (! ($settings['schedule_enabled'] ?? false)) {
            return [];
        }

        $cron = (string) ($settings['schedule_cron'] ?? '*/10 * * * *');

        if (! CronExpression::isValidExpression($cron)) {
            return [];
        }

        if (! (new CronExpression($cron))->isDue($now)) {
            return [];
        }

        return [[
            'type' => 'action',
            'name' => 'check_now',
            'payload' => ['source' => 'schedule'],
            'dry_run' => false,
        ]];
    }

    // -------------------------------------------------------------------------
    // Action Handlers
    // -------------------------------------------------------------------------

    private function handleCheckNow(PluginExecutionContext $context): PluginActionResult
    {
        $settings = $context->settings;
        ['userId' => $userId, 'profile' => $profile] = $this->resolveContext($context);

        if (! $userId) {
            return PluginActionResult::failure('Could not determine user ID. Ensure a Stream Profile is configured.');
        }

        $this->epgMode = $this->resolveEpgMode($settings);

        // Auto-migrate legacy monitored_channels into per-platform fields.
        // Mutates $settings in place. Persists to DB on a best-effort basis.
        $this->migrateLegacyMonitoredChannels($settings, $context, $context->plugin ?? null);

        $channelEntries = $this->getChannelsForPlatform('twitch', $settings, $context);
        $youTubeUrls = $this->getChannelsForPlatform('youtube', $settings, $context);
        $kickUrls = $this->getChannelsForPlatform('kick', $settings, $context);
        $genericUrls = $this->getChannelsForPlatform('generic', $settings, $context);

        if (empty($channelEntries) && empty($youTubeUrls) && empty($kickUrls) && empty($genericUrls)) {
            return PluginActionResult::failure('No channels configured. Add at least one entry to a platform section (Twitch, YouTube, Kick or Generic).');
        }

        $useApi = $this->hasTwitchApiCredentials($settings);
        $logins = array_column($channelEntries, 'login');

        if (! $useApi && count($logins) > 20) {
            $context->warning('Checking '.count($logins).' channels without Twitch API credentials - this will be slow. Consider adding API credentials for batch detection.');
        }

        $added = 0;
        $updated = 0;
        $skipped = 0;
        $cleaned = 0;
        $errors = [];
        $cookiesFile = $this->getCookiesFile($profile);
        $streamlink = null;

        // --- Heal group assignments for all existing streamarr channels ---
        // This runs unconditionally so channels whose streams are currently offline
        // also get the correct playlist-scoped group (required for Xtream visibility).
        $this->healGroupAssignments($settings, $userId, $context);

        // --- Heal Xtream compatibility channel numbering ---
        // Some IPTV Xtream clients ignore channels outside a bounded number range.
        // Keep plugin channels in a configurable safe range by default.
        $this->healXtreamCompatibilityNumbers($settings, $userId, $context);

        $context->heartbeat('Detecting live streams…', progress: 5);

        // --- Build lookup map: login → entry (for base_number) ---
        $entryMap = [];
        foreach ($channelEntries as $entry) {
            $entryMap[strtolower($entry['login'])] = $entry;
        }

        // --- Detect Twitch live streams ---
        $liveStreams = [];
        $userProfiles = [];

        if (! empty($logins)) {
            if ($useApi) {
                $context->info('Using Twitch Helix API for batch detection ('.count($logins).' channel(s))');

                $this->accessToken = $this->getAppAccessToken($settings);
                if (! $this->accessToken) {
                    $this->cleanupCookiesFile($cookiesFile);

                    return PluginActionResult::failure('Failed to obtain Twitch API access token. Check your Client ID and Client Secret.');
                }

                $userProfiles = $this->batchGetUsers($settings, $logins);
                $liveStreams = $this->batchGetStreams($settings, $logins);
            } else {
                $streamlink = $this->findStreamlink();
                if (! $streamlink) {
                    $this->cleanupCookiesFile($cookiesFile);

                    return PluginActionResult::failure('streamlink binary not found and no Twitch API credentials configured. Install streamlink or add API credentials.');
                }

                $context->info('Using streamlink fallback for '.count($logins).' channel(s)');

                foreach ($logins as $i => $login) {
                    $context->heartbeat("Checking {$login}…", progress: (int) (5 + ($i / count($logins)) * 60));
                    $streamInfo = $this->checkChannelLiveViaStreamlink($streamlink, $login, $cookiesFile);

                    if ($streamInfo) {
                        $liveStreams[] = $streamInfo;
                    }
                }
            }
        }

        // Fetch profile images for live channels (streamlink does not provide them)
        if (! $useApi && ! empty($liveStreams)) {
            foreach ($liveStreams as $i => &$stream) {
                if (empty($stream['profile_image'])) {
                    $context->heartbeat("Fetching avatar for {$stream['login']}…", progress: (int) (65 + ($i / count($liveStreams)) * 5));
                    $stream['profile_image'] = $this->fetchProfileImageFallback($stream['login']);
                }
            }
            unset($stream);
        }

        $context->heartbeat('Processing live streams…', progress: 70);

        // --- Ensure EPG source for programme guide ---
        try {
            $this->ensureEpgSource($userId);
        } catch (\Throwable $e) {
            $this->epgSource = null;
            $context->warning('EPG initialization failed, continuing without EPG data.');
            $errors[] = 'EPG init: '.$e->getMessage();
        }

        // --- Process live streams ---
        foreach ($liveStreams as $stream) {
            $login = strtolower($stream['login']);

            $existing = Channel::where('user_id', $userId)
                ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
                ->whereJsonContains('info->twitch_login', $login)
                ->whereJsonContains('info->twitch_stream_type', self::STREAM_TYPE_LIVE)
                ->first();

            if ($existing) {
                // Ensure EPG channel is linked for programme guide
                if (! $existing->epg_channel_id && $this->epgSource) {
                    try {
                        $logo = $stream['profile_image'] ?: ($userProfiles[$login]['profile_image'] ?? '');
                        $epgChannel = $this->ensureEpgChannel($this->epgSource, $userId, $login, [
                            'display_name' => $stream['display_name'] ?? $login,
                            'logo' => $logo,
                            'language' => $stream['language'] ?? '',
                        ]);
                        $existing->update(['epg_channel_id' => $epgChannel->id]);
                    } catch (\Throwable $e) {
                        $context->warning("{$login}: failed to sync EPG channel, continuing.");
                        $errors[] = "{$login} EPG sync: {$e->getMessage()}";
                    }
                }

                if ($this->updateExistingChannel($existing, $stream, $settings, $userId, $userProfiles)) {
                    $context->info("{$login}: updated title/game for channel #{$existing->channel}");
                    $updated++;
                } else {
                    $skipped++;
                }

                continue;
            }

            $entry = $entryMap[$login] ?? null;
            $baseNumber = $entry['base_number'] ?? null;
            $channelNumber = $this->nextChannelNumber($userId, $settings, $login, $baseNumber);
            $logo = $stream['profile_image'] ?: ($userProfiles[$login]['profile_image'] ?? '');
            $streamTitle = trim((string) ($stream['title'] ?? "{$login} - Live"));
            $channelTitle = $this->resolveLiveChannelTitle([
                'login' => $login,
                'display_name' => $stream['display_name'] ?? $login,
                'title' => $streamTitle,
            ], $settings);

            $metadata = [
                'login' => $login,
                'display_name' => $stream['display_name'] ?? $login,
                'user_id' => $stream['user_id'] ?? ($userProfiles[$login]['user_id'] ?? ''),
                'title' => $channelTitle,
                'stream_title' => $streamTitle,
                'game' => $stream['game'] ?? '',
                'game_box_art' => $stream['game_box_art'] ?? '',
                'stream_started' => $stream['started_at'] ?? Carbon::now()->toISOString(),
                'logo' => $logo,
                'thumbnail' => $stream['thumbnail'] ?? '',
                'language' => $stream['language'] ?? '',
            ];

            // Create EPG channel for programme guide
            if ($this->epgSource) {
                try {
                    $epgChannel = $this->ensureEpgChannel($this->epgSource, $userId, $login, $metadata);
                    $metadata['epg_channel_id'] = $epgChannel->id;
                } catch (\Throwable $e) {
                    $context->warning("{$login}: failed to create EPG channel, continuing.");
                    $errors[] = "{$login} EPG create: {$e->getMessage()}";
                }
            }

            try {
                $this->createChannel($metadata, self::STREAM_TYPE_LIVE, $settings, $userId, $channelNumber);
                $context->info("{$login}: added live channel #{$channelNumber} - '{$metadata['title']}'");
                $added++;
            } catch (\Throwable $e) {
                $context->error("{$login}: failed to create channel - {$e->getMessage()}");
                $errors[] = "{$login}: {$e->getMessage()}";
            }
        }

        // --- VODs (API only) ---
        if (($settings['include_vods'] ?? false) && $useApi) {
            $vodLimit = max(1, min(100, (int) ($settings['vod_limit'] ?? 5)));
            $context->heartbeat('Fetching VODs…', progress: 80);

            foreach ($logins as $login) {
                $twitchUserId = $userProfiles[strtolower($login)]['user_id'] ?? null;

                if (! $twitchUserId) {
                    continue;
                }

                $vods = $this->getChannelVideos($settings, $twitchUserId, $vodLimit);
                $entry = $entryMap[strtolower($login)] ?? null;
                $baseNumber = $entry['base_number'] ?? null;

                foreach ($vods as $vod) {
                    $vodId = $vod['id'];

                    $existing = Channel::where('user_id', $userId)
                        ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
                        ->whereJsonContains('info->twitch_vod_id', $vodId)
                        ->first();

                    if ($existing) {
                        $expectedExtension = $this->resolveContainerExtension($settings, true);
                        if ($existing->container_extension !== $expectedExtension) {
                            $vodMovieData = $existing->movie_data ?? [];
                            if (! empty($vodMovieData)) {
                                $vodMovieData['container_extension'] = $expectedExtension;
                            }
                            $existing->update([
                                'container_extension' => $expectedExtension,
                                'movie_data' => $vodMovieData ?: null,
                            ]);
                        }
                        $skipped++;

                        continue;
                    }

                    $channelNumber = $this->nextChannelNumber($userId, $settings, strtolower($login), $baseNumber);
                    $logo = $userProfiles[strtolower($login)]['profile_image'] ?? '';

                    $metadata = [
                        'login' => strtolower($login),
                        'display_name' => $userProfiles[strtolower($login)]['display_name'] ?? $login,
                        'user_id' => $twitchUserId,
                        'title' => $vod['title'] ?? "{$login} - VOD",
                        'game' => '',
                        'game_box_art' => '',
                        'logo' => $logo,
                        'thumbnail' => $vod['thumbnail'] ?? '',
                        'vod_id' => $vodId,
                        'vod_data' => $vod,
                    ];

                    try {
                        $this->createChannel($metadata, self::STREAM_TYPE_VOD, $settings, $userId, $channelNumber);
                        $context->info("{$login}: added VOD #{$vodId} as channel #{$channelNumber}");
                        $added++;
                    } catch (\Throwable $e) {
                        $context->error("{$login}: failed to create VOD channel - {$e->getMessage()}");
                        $errors[] = "{$login} VOD: {$e->getMessage()}";
                    }
                }
            }
        } elseif (($settings['include_vods'] ?? false) && ! $useApi) {
            $context->warning('VOD discovery requires Twitch API credentials - skipping VODs.');
        }

        // --- Detect non-Twitch live streams via streamlink (provider-driven loop) ---
        // YouTube, Kick and the generic catch-all share an identical detection
        // mechanism (`streamlink --json <url>`); the only differences are the
        // group label and, for YouTube, the dedicated channel-creator that
        // wires up an EPG entry. Iterating over the registry keeps the loop
        // body tiny and lets later phases swap in per-provider detection.
        $registry = $this->buildRegistry($settings);
        $progressBase = 83;
        foreach ($registry->all() as $provider) {
            if ($provider->id() === 'twitch') {
                continue;
            }

            $urls = $this->getChannelsForPlatform($provider->id(), $settings, $context);
            if (empty($urls)) {
                continue;
            }

            // Kick takes the provider-driven path (real API + optional
            // streamlink fallback for entries the API could not resolve).
            // YouTube and Generic stay on the inline streamlink probe until
            // their respective provider classes grow real detection in
            // later phases.
            if ($provider->id() === 'kick') {
                $context->heartbeat("Checking {$provider->displayName()} live streams…", progress: $progressBase);
                $progressBase = min(89, $progressBase + 1);

                /** @var Providers\KickProvider $kickProvider */
                $kickProvider = $provider;
                $kickEntries = [];
                foreach ($urls as $url) {
                    $entry = $kickProvider->parseEntry($url);
                    if ($entry) {
                        $kickEntries[] = $entry;
                    }
                }

                $apiResults = [];
                if (! empty($kickEntries)) {
                    $apiResults = $kickProvider->detectLive($kickEntries, $settings, $cookiesFile);
                }
                $fallbackUrls = $kickProvider->getPendingFallback();

                foreach ($urls as $url) {
                    $info = $apiResults[$url] ?? null;

                    if ($info === null && in_array($url, $fallbackUrls, true)) {
                        $streamlink = $streamlink ?? $this->findStreamlink();
                        if (! $streamlink) {
                            $context->warning("streamlink not found. Kick fallback for {$url} skipped.");
                            continue;
                        }
                        $context->heartbeat("Checking Kick fallback: {$url}…");
                        $info = $this->checkYouTubeLiveViaStreamlink($streamlink, $url, $cookiesFile);
                    }

                    if ($info) {
                        try {
                            $wasNew = $this->createOrUpdateGenericChannel($info, $settings, $userId, $context, 'kick');
                            if ($wasNew) {
                                $added++;
                            } else {
                                $updated++;
                            }
                        } catch (\Throwable $e) {
                            $context->error("Kick {$url}: failed - {$e->getMessage()}");
                            $errors[] = "Kick {$url}: {$e->getMessage()}";
                        }
                    } else {
                        $context->info("Kick not live: {$url}");
                    }
                }

                // Optional Kick VOD discovery (independent of Twitch's include_vods).
                if (($settings['include_kick_vods'] ?? false) && ! empty($kickEntries)) {
                    $kickVodLimit = max(1, min(50, (int) ($settings['max_kick_vods_per_channel'] ?? 5)));
                    $context->heartbeat('Fetching Kick VODs…', progress: min(89, $progressBase + 1));

                    foreach ($kickEntries as $entry) {
                        try {
                            $vods = $kickProvider->listVods($entry, $kickVodLimit, $settings);
                        } catch (\Throwable $e) {
                            $context->warning("Kick VOD listing failed for {$entry->label}: {$e->getMessage()}");
                            continue;
                        }

                        foreach ($vods as $vod) {
                            try {
                                $wasNew = $this->createOrUpdateKickVod($vod, $entry, $settings, $userId, $context);
                                if ($wasNew) {
                                    $added++;
                                } else {
                                    $skipped++;
                                }
                            } catch (\Throwable $e) {
                                $context->error("Kick VOD {$vod->vodId}: failed - {$e->getMessage()}");
                                $errors[] = "Kick VOD {$vod->vodId}: {$e->getMessage()}";
                            }
                        }
                    }
                }

                continue;
            }

            // YouTube takes the provider-driven path when an API key is
            // configured (real YouTube Data API v3 lookup); otherwise it
            // falls through to the inline streamlink probe below, which
            // is byte-identical to pre-v1.15 behaviour.
            if ($provider->id() === 'youtube') {
                $context->heartbeat("Checking {$provider->displayName()} live streams…", progress: $progressBase);
                $progressBase = min(89, $progressBase + 1);

                /** @var Providers\YouTubeProvider $ytProvider */
                $ytProvider = $provider;
                $ytEntries = [];
                foreach ($urls as $url) {
                    $entry = $ytProvider->parseEntry($url);
                    if ($entry) {
                        $ytEntries[] = $entry;
                    }
                }

                $apiResults = [];
                if (! empty($ytEntries)) {
                    $apiResults = $ytProvider->detectLive($ytEntries, $settings, $cookiesFile);
                }
                $fallbackUrls = $ytProvider->getPendingFallback();

                foreach ($urls as $url) {
                    $info = $apiResults[$url] ?? null;

                    if ($info === null && in_array($url, $fallbackUrls, true)) {
                        $streamlink = $streamlink ?? $this->findStreamlink();
                        if (! $streamlink) {
                            $context->warning("streamlink not found. YouTube fallback for {$url} skipped.");
                            continue;
                        }
                        $context->heartbeat("Checking YouTube fallback: {$url}…");
                        $info = $this->checkYouTubeLiveViaStreamlink($streamlink, $url, $cookiesFile);
                    }

                    if ($info) {
                        try {
                            $wasNew = $this->createOrUpdateYouTubeChannel($info, $settings, $userId, $context);
                            if ($wasNew) {
                                $added++;
                            } else {
                                $updated++;
                            }
                        } catch (\Throwable $e) {
                            $context->error("YouTube {$url}: failed - {$e->getMessage()}");
                            $errors[] = "YouTube {$url}: {$e->getMessage()}";
                        }
                    } else {
                        $context->info("YouTube not live: {$url}");
                    }
                }

                continue;
            }

            $streamlink = $streamlink ?? $this->findStreamlink();
            if (! $streamlink) {
                $context->warning("streamlink not found. {$provider->displayName()} streams cannot be checked. Install streamlink to enable monitoring for the remaining platforms.");
                break;
            }

            $context->heartbeat("Checking {$provider->displayName()} live streams…", progress: $progressBase);
            $progressBase = min(89, $progressBase + 1);

            // Prefer host-based labels for the catch-all provider so logs say
            // "Checking dlive.tv: ..." instead of "Checking Live Streams: ...".
            // Specific providers keep their displayName().
            foreach ($urls as $url) {
                $label = $provider->id() === 'generic'
                    ? ($this->extractHostLabel($url) ?: $provider->displayName())
                    : $provider->displayName();

                $context->heartbeat("Checking {$label}: {$url}…");
                $info = $this->checkYouTubeLiveViaStreamlink($streamlink, $url, $cookiesFile);

                if ($info) {
                    try {
                        if ($provider->id() === 'youtube') {
                            $wasNew = $this->createOrUpdateYouTubeChannel($info, $settings, $userId, $context);
                        } else {
                            $platformHint = $provider->id() === 'generic' ? null : $provider->id();
                            $wasNew = $this->createOrUpdateGenericChannel($info, $settings, $userId, $context, $platformHint);
                        }

                        if ($wasNew) {
                            $added++;
                        } else {
                            $updated++;
                        }
                    } catch (\Throwable $e) {
                        $context->error("{$label} {$url}: failed - {$e->getMessage()}");
                        $errors[] = "{$label} {$url}: {$e->getMessage()}";
                    }
                } else {
                    $context->info("{$label} not live: {$url}");
                }
            }
        }

        // --- Cleanup ---
        if ($settings['auto_cleanup'] ?? true) {
            $context->heartbeat('Cleaning up ended streams…', progress: 90);
            $cleaned = $this->cleanupEndedStreams($settings, $userId, $cookiesFile, $context);
        }

        $this->cleanupCookiesFile($cookiesFile);
        $this->accessToken = null;

        // Write EPG programme data for all live channels
        if ($this->epgSource) {
            try {
                $this->writeEpgData($userId);
            } catch (\Throwable $e) {
                $context->warning('EPG write failed, channels were still processed.');
                $errors[] = 'EPG write: '.$e->getMessage();
            }
        }
        $this->epgSource = null;
        $this->epgMode = 'game';

        $context->heartbeat('Done', progress: 100);

        $parts = [];
        if ($added) {
            $parts[] = "{$added} channel(s) added";
        }
        if ($updated) {
            $parts[] = "{$updated} channel(s) updated";
        }
        if ($skipped) {
            $parts[] = "{$skipped} already tracked";
        }
        if ($cleaned) {
            $parts[] = "{$cleaned} ended channel(s) removed";
        }
        if (empty($parts)) {
            $parts[] = 'No changes';
        }

        $summary = implode(', ', $parts);
        if ($errors) {
            $summary .= '. Errors: '.implode('; ', array_slice($errors, 0, 3));
        }

        return PluginActionResult::success($summary, [
            'added' => $added,
            'updated' => $updated,
            'skipped' => $skipped,
            'cleaned' => $cleaned,
        ]);
    }

    private function handleRepairXtream(PluginExecutionContext $context): PluginActionResult
    {
        $settings = $context->settings;
        ['userId' => $userId] = $this->resolveContext($context);

        if (! $userId) {
            return PluginActionResult::failure('Could not determine user ID. Ensure a Stream Profile is configured.');
        }

        // Force compatibility repair regardless of stored toggle state.
        $repairSettings = $settings;
        $repairSettings['xtream_compat_mode'] = true;

        $this->healGroupAssignments($repairSettings, $userId, $context);
        $this->healXtreamCompatibilityNumbers($repairSettings, $userId, $context);
        $this->healXtreamTextCompatibility($repairSettings, $userId, $context);

        return PluginActionResult::success('Xtream repair completed. Group mapping, channel numbering, and text compatibility were reconciled.');
    }

    private function handleAddManual(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $settings = $context->settings;
        $this->epgMode = $this->resolveEpgMode($settings);
        ['userId' => $userId, 'profile' => $profile] = $this->resolveContext($context);

        if (! $userId) {
            return PluginActionResult::failure('Could not determine user ID. Ensure a Stream Profile is configured.');
        }

        $rawUrls = trim($payload['manual_urls'] ?? '');
        if (! $rawUrls) {
            return PluginActionResult::failure('No URL provided.');
        }

        $urls = array_filter(array_map('trim', preg_split('/[\n,]+/', $rawUrls)));
        if (empty($urls)) {
            return PluginActionResult::failure('No valid URLs found.');
        }

        $useApi = $this->hasTwitchApiCredentials($settings);
        if ($useApi) {
            $this->accessToken = $this->getAppAccessToken($settings);
        }

        $added = 0;
        $skipped = 0;
        $errors = [];
        $cookiesFile = $this->getCookiesFile($profile);

        foreach ($urls as $url) {
            $parsed = $this->parseTwitchUrl($url);

            if (! $parsed) {
                $errors[] = 'Could not parse Twitch URL: '.substr($url, 0, 80);

                continue;
            }

            if ($parsed['type'] === 'channel') {
                $login = strtolower($parsed['value']);

                $existing = Channel::where('user_id', $userId)
                    ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
                    ->whereJsonContains('info->twitch_login', $login)
                    ->whereJsonContains('info->twitch_stream_type', self::STREAM_TYPE_LIVE)
                    ->first();

                if ($existing) {
                    $context->info("{$login}: already tracked as channel #{$existing->channel}");
                    $skipped++;

                    continue;
                }

                // Check if actually live
                $streamInfo = null;
                if ($useApi && $this->accessToken) {
                    $streams = $this->batchGetStreams($settings, [$login]);
                    $streamInfo = $streams[0] ?? null;
                } else {
                    $streamlink = $this->findStreamlink();
                    if ($streamlink) {
                        $streamInfo = $this->checkChannelLiveViaStreamlink($streamlink, $login, $cookiesFile);
                    }
                }

                if (! $streamInfo) {
                    $errors[] = "{$login} is not currently live";

                    continue;
                }

                $userProfile = [];
                if ($useApi && $this->accessToken) {
                    $profiles = $this->batchGetUsers($settings, [$login]);
                    $userProfile = $profiles[$login] ?? [];
                }

                // Fetch profile image from channel page when API is not available
                if (! $useApi && empty($streamInfo['profile_image'])) {
                    $streamInfo['profile_image'] = $this->fetchProfileImageFallback($login);
                }

                $channelNumber = $this->nextChannelNumber($userId, $settings, $login, null);
                $streamTitle = trim((string) ($streamInfo['title'] ?? "{$login} - Live"));
                $channelTitle = $this->resolveLiveChannelTitle([
                    'login' => $login,
                    'display_name' => $streamInfo['display_name'] ?? $login,
                    'title' => $streamTitle,
                ], $settings);
                $metadata = [
                    'login' => $login,
                    'display_name' => $streamInfo['display_name'] ?? $login,
                    'user_id' => $streamInfo['user_id'] ?? ($userProfile['user_id'] ?? ''),
                    'title' => $channelTitle,
                    'stream_title' => $streamTitle,
                    'game' => $streamInfo['game'] ?? '',
                    'game_box_art' => $streamInfo['game_box_art'] ?? '',
                    'stream_started' => $streamInfo['started_at'] ?? Carbon::now()->toISOString(),
                    'logo' => $streamInfo['profile_image'] ?? ($userProfile['profile_image'] ?? ''),
                    'thumbnail' => $streamInfo['thumbnail'] ?? '',
                    'language' => $streamInfo['language'] ?? '',
                ];

                // Create EPG channel for programme guide
                try {
                    $epg = $this->ensureEpgSource($userId);
                    $epgChannel = $this->ensureEpgChannel($epg, $userId, $login, $metadata);
                    $metadata['epg_channel_id'] = $epgChannel->id;
                } catch (\Throwable $e) {
                    $context->warning("{$login}: failed to create EPG channel, continuing.");
                    $errors[] = "{$login} EPG create: {$e->getMessage()}";
                }

                try {
                    $this->createChannel($metadata, self::STREAM_TYPE_LIVE, $settings, $userId, $channelNumber);
                    $context->info("Added '{$metadata['title']}' as channel #{$channelNumber}");
                    $added++;
                } catch (\Throwable $e) {
                    $context->error("Failed to create channel for {$login}: {$e->getMessage()}");
                    $errors[] = $e->getMessage();
                }
            } elseif ($parsed['type'] === 'vod') {
                $vodId = $parsed['value'];

                $existing = Channel::where('user_id', $userId)
                    ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
                    ->whereJsonContains('info->twitch_vod_id', $vodId)
                    ->first();

                if ($existing) {
                    $context->info("VOD {$vodId}: already tracked as channel #{$existing->channel}");
                    $skipped++;

                    continue;
                }

                // Fetch VOD details from API if available
                $vodData = [];
                $vodTitle = "Twitch VOD #{$vodId}";
                $vodLogin = 'vod';
                $vodDisplayName = 'VOD';
                $vodUserId = '';
                $vodLogo = '';
                $vodThumbnail = '';

                if ($useApi && $this->accessToken) {
                    $vodInfo = $this->getVideoById($settings, $vodId);

                    if ($vodInfo) {
                        $vodData = $vodInfo;
                        $vodTitle = $vodInfo['title'] ?? $vodTitle;
                        $vodLogin = strtolower($vodInfo['user_login'] ?? 'vod');
                        $vodDisplayName = $vodInfo['user_name'] ?? $vodLogin;
                        $vodUserId = $vodInfo['user_id'] ?? '';
                        $vodThumbnail = $vodInfo['thumbnail'] ?? '';

                        $profiles = $this->batchGetUsers($settings, [$vodLogin]);
                        $vodLogo = $profiles[$vodLogin]['profile_image'] ?? '';
                    }
                }

                $channelNumber = $this->nextChannelNumber($userId, $settings, $vodLogin, null);
                $metadata = [
                    'login' => $vodLogin,
                    'display_name' => $vodDisplayName,
                    'user_id' => $vodUserId,
                    'title' => $vodTitle,
                    'game' => '',
                    'game_box_art' => '',
                    'logo' => $vodLogo,
                    'thumbnail' => $vodThumbnail,
                    'vod_id' => $vodId,
                    'vod_data' => $vodData,
                ];

                try {
                    $this->createChannel($metadata, self::STREAM_TYPE_VOD, $settings, $userId, $channelNumber);
                    $context->info("Added VOD #{$vodId} as channel #{$channelNumber}");
                    $added++;
                } catch (\Throwable $e) {
                    $context->error("Failed to create VOD channel for {$vodId}: {$e->getMessage()}");
                    $errors[] = $e->getMessage();
                }
            }
        }

        $this->cleanupCookiesFile($cookiesFile);
        $this->accessToken = null;

        // Write EPG programme data
        if ($this->epgSource) {
            try {
                $this->writeEpgData($userId);
            } catch (\Throwable $e) {
                $context->warning('EPG write failed, manual add still completed.');
                $errors[] = 'EPG write: '.$e->getMessage();
            } finally {
                $this->epgSource = null;
            }
        }

        $parts = [];
        if ($added) {
            $parts[] = "{$added} channel(s) added";
        }
        if ($skipped) {
            $parts[] = "{$skipped} already tracked";
        }
        if ($errors) {
            $parts[] = count($errors).' failed';
        }

        $summary = implode(', ', $parts) ?: 'No streams processed';
        if ($errors && count($errors) <= 3) {
            $summary .= '. '.implode('; ', $errors);
        }

        return $added > 0
            ? PluginActionResult::success($summary)
            : PluginActionResult::failure($summary);
    }

    private function handleTestUrl(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $url = trim($payload['url'] ?? '');

        if ($url === '') {
            return PluginActionResult::failure('No URL provided.');
        }

        ['profile' => $profile] = $this->resolveContext($context);

        $streamlink = $this->findStreamlink();

        if (! $streamlink) {
            return PluginActionResult::failure('streamlink binary not found. Install streamlink to use URL testing.');
        }

        $cookiesFile = $this->getCookiesFile($profile);

        try {
            $cmd = [$streamlink, '--json', $url];

            if ($cookiesFile && is_file($cookiesFile)) {
                $cmd[] = '--http-cookies-file';
                $cmd[] = $cookiesFile;
            }

            $context->info("Running: streamlink --json {$url}");
            $result = $this->runProcess($cmd, 45);

            $json = $result['stdout'] ? json_decode($result['stdout'], true) : null;
            $isLive = is_array($json) && ! isset($json['error']) && isset($json['streams']);
            $streams = is_array($json) ? array_keys($json['streams'] ?? []) : [];
            $metadata = is_array($json) ? ($json['metadata'] ?? []) : [];
            $errorMsg = is_array($json) ? ($json['error'] ?? null) : null;
            $stderr = $result['stderr'] ? substr($result['stderr'], -2000) : '';

            if ($isLive) {
                return PluginActionResult::success(
                    "Stream is LIVE: {$url}",
                    [
                        'live' => true,
                        'url' => $url,
                        'metadata' => $metadata,
                        'streams' => $streams,
                        'exit_code' => $result['exit'],
                    ]
                );
            }

            return PluginActionResult::success(
                "Stream is NOT live or URL not supported: {$url}" . ($errorMsg ? ". {$errorMsg}" : ''),
                [
                    'live' => false,
                    'url' => $url,
                    'error' => $errorMsg,
                    'stderr_tail' => $stderr,
                    'exit_code' => $result['exit'],
                ]
            );
        } finally {
            $this->cleanupCookiesFile($cookiesFile);
        }
    }

    private function handleCleanup(PluginExecutionContext $context): PluginActionResult
    {
        $settings = $context->settings;
        $this->epgMode = $this->resolveEpgMode($settings);
        ['userId' => $userId, 'profile' => $profile] = $this->resolveContext($context);

        if (! $userId) {
            return PluginActionResult::failure('Could not determine user ID.');
        }

        $cookiesFile = $this->getCookiesFile($profile);

        if ($this->hasTwitchApiCredentials($settings)) {
            $this->accessToken = $this->getAppAccessToken($settings);
        }

        $cleaned = $this->cleanupEndedStreams($settings, $userId, $cookiesFile, $context);

        $this->cleanupCookiesFile($cookiesFile);
        $this->accessToken = null;

        // Rewrite EPG data after removing ended streams
        try {
            $this->writeEpgData($userId);
        } catch (\Throwable $e) {
            $context->warning('EPG rewrite failed after cleanup, cleanup itself succeeded.');
        } finally {
            $this->epgSource = null;
        }

        return PluginActionResult::success("Removed {$cleaned} ended channel(s).", ['cleaned' => $cleaned]);
    }

    private function handleResetAll(PluginExecutionContext $context): PluginActionResult
    {
        ['userId' => $userId] = $this->resolveContext($context);

        if (! $userId) {
            return PluginActionResult::failure('Could not determine user ID.');
        }

        $channels = Channel::where('user_id', $userId)
            ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
            ->get();

        $count = $channels->count();
        $channels->each(fn (Channel $channel) => $channel->delete());

        // Clean up EPG data
        $epg = Epg::where('name', 'Streamarr')
            ->where('user_id', $userId)
            ->where('source_type', EpgSourceType::URL)
            ->first();

        if ($epg) {
            Storage::disk('local')->deleteDirectory("epg-cache/{$epg->uuid}");
            EpgChannel::where('epg_id', $epg->id)->where('user_id', $userId)->delete();
            $epg->update([
                'is_cached' => false,
                'cache_meta' => null,
                'channel_count' => 0,
                'programme_count' => 0,
            ]);
        }

        $context->info("Deleted {$count} channel(s) created by Streamarr.");

        return PluginActionResult::success("Reset complete - deleted {$count} channel(s).", ['deleted' => $count]);
    }

    /**
     * Normalize EPG mode from plugin settings.
     * Maps legacy/alternate values to either "game" or "title".
     */
    private function resolveContainerExtension(array $settings, bool $isVod): ?string
    {
        if (($settings['output_format'] ?? 'ts') === 'hls') {
            return 'm3u8';
        }

        return $isVod ? 'ts' : null;
    }

    private function resolveEpgMode(array $settings): string
    {
        $rawMode = strtolower(trim((string) ($settings['epg_mode'] ?? 'game')));

        return match ($rawMode) {
            'title', 'stream_title', 'stream-title', 'streamtitle' => 'title',
            default => 'game',
        };
    }

    /**
     * Normalize live channel title mode from plugin settings.
     * Maps legacy/alternate values to either "stream_title" or "channel_name".
     */
    private function resolveLiveChannelTitleMode(array $settings): string
    {
        $rawMode = strtolower(trim((string) ($settings['live_channel_title_mode'] ?? 'stream_title')));

        return match ($rawMode) {
            'channel', 'channel_name', 'channel-name', 'name', 'display_name', 'display-name' => 'channel_name',
            default => 'stream_title',
        };
    }

    /**
     * Build the channel title for live streams based on configured title mode.
     *
     * @param  array{login?: string, display_name?: string, title?: string}  $stream
     */
    private function resolveLiveChannelTitle(array $stream, array $settings): string
    {
        $mode = $this->resolveLiveChannelTitleMode($settings);

        $login = trim((string) ($stream['login'] ?? ''));
        $displayName = trim((string) ($stream['display_name'] ?? ''));
        $streamTitle = trim((string) ($stream['title'] ?? ''));

        if ($mode === 'channel_name') {
            if ($displayName !== '') {
                return $displayName;
            }

            if ($login !== '') {
                return $login;
            }

            return $streamTitle;
        }

        if ($streamTitle !== '') {
            return $streamTitle;
        }

        if ($displayName !== '') {
            return $displayName;
        }

        return $login;
    }

    // -------------------------------------------------------------------------
    // Channel lifecycle
    // -------------------------------------------------------------------------

    /**
     * @param  array{login: string, display_name: string, user_id: string, title: string, game: string, game_box_art: string, logo: string, thumbnail: string, language?: string, stream_title?: string, vod_id?: string, vod_data?: array}  $metadata
     */
    private function createChannel(
        array $metadata,
        string $streamType,
        array $settings,
        int $userId,
        int|float $channelNumber,
    ): Channel {
        $login = $metadata['login'];
        $isVod = $streamType === self::STREAM_TYPE_VOD;
        $vodId = $metadata['vod_id'] ?? null;
        $vodData = $metadata['vod_data'] ?? [];

        $url = $isVod && $vodId
            ? "https://www.twitch.tv/videos/{$vodId}"
            : "https://www.twitch.tv/{$login}";

        $groupName = $this->resolveGroupName($metadata, $streamType, $settings);
        $profileId = (int) ($settings['stream_profile_id'] ?? 0);
        $playlistId = (int) ($settings['target_playlist_id'] ?? 0) ?: null;
        $customPlaylistId = (int) ($settings['target_custom_playlist_id'] ?? 0) ?: null;
        $channelTitle = $this->sanitizeXtreamText((string) ($metadata['title'] ?? ''), $settings);
        $channelName = $this->sanitizeXtreamText((string) ($metadata['display_name'] ?? $login), $settings);

        if ($channelName === '') {
            $channelName = $this->sanitizeXtreamText($login, $settings);
            if ($channelName === '') {
                $channelName = $login;
            }
        }

        if ($channelTitle === '') {
            $channelTitle = $channelName;
        }

        $group = $this->resolveOrCreateGroup($groupName, $userId, $playlistId, $customPlaylistId);

        $customPlaylist = $customPlaylistId ? CustomPlaylist::find($customPlaylistId) : null;

        $groupTag = null;
        if ($customPlaylist && ! empty($customPlaylist->uuid)) {
            $groupTag = $customPlaylist->groupTags()->where('name->en', $groupName)->first();
            if (! $groupTag) {
                $groupTag = Tag::create(['name' => ['en' => $groupName], 'type' => $customPlaylist->uuid]);
                $customPlaylist->attachTag($groupTag);
            }
        }

        $info = [
            'plugin' => self::PLUGIN_MARKER,
            'twitch_login' => $login,
            'twitch_user_id' => $metadata['user_id'] ?? '',
            'twitch_display_name' => $metadata['display_name'] ?? $login,
            'twitch_stream_type' => $streamType,
        ];

        // Initialize EPG history for live channels
        if (! $isVod) {
            $rawStreamTitle = trim((string) ($metadata['stream_title'] ?? $metadata['title'] ?? ''));

            $info['twitch_epg_history'] = [[
                'game' => $metadata['game'] ?? '',
                'game_box_art' => $metadata['game_box_art'] ?? '',
                'started_at' => Carbon::now()->toISOString(),
            ]];
            $info['twitch_title'] = $rawStreamTitle;
            $info['twitch_stream_started'] = $metadata['stream_started'] ?? Carbon::now()->toISOString();
            $info['twitch_title_history'] = [[
                'title' => $rawStreamTitle,
                'started_at' => $metadata['stream_started'] ?? Carbon::now()->toISOString(),
            ]];
        }

        if ($vodId) {
            $info['twitch_vod_id'] = $vodId;
        }

        if (! empty($metadata['game'])) {
            $info['twitch_game'] = $metadata['game'];
        }

        if (! empty($metadata['game_box_art'])) {
            $info['twitch_game_box_art'] = $metadata['game_box_art'];
        }

        // Xtream-compatible VOD metadata
        if ($isVod && ! empty($vodData)) {
            $info['description'] = $vodData['description'] ?? '';
            $info['plot'] = $vodData['description'] ?? '';
            $info['duration'] = $vodData['duration_formatted'] ?? '';
            $info['duration_secs'] = $vodData['duration_secs'] ?? 0;
            $info['episode_run_time'] = $vodData['episode_run_time'] ?? 0;
            $info['release_date'] = $vodData['published_at'] ?? '';
            $info['releasedate'] = substr($vodData['published_at'] ?? '', 0, 10);
            $info['cover_big'] = $metadata['thumbnail'] ?? '';
            $info['movie_image'] = $metadata['logo'] ?? '';
            $info['cast'] = $metadata['display_name'] ?? $login;
            $info['genre'] = 'Twitch VOD';
            $info['language'] = $vodData['language'] ?? '';
            $info['twitch_view_count'] = $vodData['view_count'] ?? 0;
            $info['twitch_vod_type'] = $vodData['vod_type'] ?? 'archive';
            $info['twitch_stream_id'] = $vodData['stream_id'] ?? '';
            $info['twitch_duration_raw'] = $vodData['duration_raw'] ?? '';
            $info['twitch_created_at'] = $vodData['created_at'] ?? '';
            $info['twitch_published_at'] = $vodData['published_at'] ?? '';

            if (! empty($vodData['muted_segments'])) {
                $info['twitch_muted_segments'] = $vodData['muted_segments'];
            }
        }

        // Extract year from VOD created_at or published_at
        $year = null;
        if ($isVod && ! empty($vodData['created_at'])) {
            $year = substr($vodData['created_at'], 0, 4);
        }

        // Build Xtream movie_data for VODs
        $movieData = null;
        if ($isVod) {
            $addedTimestamp = '';
            if (! empty($vodData['created_at'])) {
                $addedTimestamp = (string) strtotime($vodData['created_at']);
            }

            $movieData = [
                'stream_id' => 0,
                'name' => $channelTitle,
                'title' => $channelTitle,
                'year' => $year ?? '',
                'added' => $addedTimestamp ?: (string) time(),
                'category_id' => (string) ($group?->id ?? ''),
                'category_ids' => $group ? [$group->id] : [],
                'container_extension' => $this->resolveContainerExtension($settings, true),
                'custom_sid' => '',
                'direct_source' => '',
            ];
        }

        $channel = Channel::create([
            'uuid' => Str::orderedUuid()->toString(),
            'name' => $channelName,
            'title' => $channelTitle,
            'url' => $url,
            'channel' => $channelNumber,
            'sort' => (float) $channelNumber,
            'stream_id' => 'streamarr-'.$login,
            'epg_channel_id' => $metadata['epg_channel_id'] ?? null,
            'lang' => $metadata['language'] ?? '',
            'group' => $groupName,
            'group_internal' => $groupName,
            'is_custom' => true,
            'is_vod' => $isVod,
            'enabled' => true,
            'shift' => 0,
            'logo_internal' => $metadata['logo'] ?? '',
            'logo_type' => 'channel',
            'enable_proxy' => true,
            'user_id' => $userId,
            'group_id' => $group?->id,
            'stream_profile_id' => $profileId ?: null,
            'playlist_id' => $playlistId,
            'custom_playlist_id' => $playlistId ? null : $customPlaylistId,
            'info' => $info,
            'container_extension' => $this->resolveContainerExtension($settings, $isVod),
            'year' => $year,
            'movie_data' => $movieData,
        ]);

        // Update movie_data.stream_id with actual channel ID
        if ($isVod && $movieData) {
            $movieData['stream_id'] = $channel->id;
            $channel->update(['movie_data' => $movieData]);
        }

        // Sync with custom playlist if one was specified
        if ($customPlaylistId) {
            if (! $customPlaylist) {
                $customPlaylist = CustomPlaylist::find($customPlaylistId);
            }
            if ($customPlaylist) {
                $channel->customPlaylists()->syncWithoutDetaching([$customPlaylist->id]);
                if ($groupTag) {
                    $channel->attachTag($groupTag);
                }
            }
        }

        return $channel;
    }

    /**
     * Update an existing live channel with fresh stream data (title, game, group).
     * Returns true if any field was actually changed.
     */
    private function updateExistingChannel(Channel $channel, array $stream, array $settings, int $userId, array $userProfiles = []): bool
    {
        $changed = false;
        $info = $channel->info ?? [];

        // Backfill missing playlist assignment for legacy channels.
        if (! $channel->playlist_id && ! $channel->custom_playlist_id) {
            $targetPlaylistId = (int) ($settings['target_playlist_id'] ?? 0) ?: null;
            $targetCustomPlaylistId = (int) ($settings['target_custom_playlist_id'] ?? 0) ?: null;

            if ($targetPlaylistId) {
                $channel->playlist_id = $targetPlaylistId;
                $channel->custom_playlist_id = null;
                $changed = true;
            } elseif ($targetCustomPlaylistId) {
                $channel->custom_playlist_id = $targetCustomPlaylistId;
                $changed = true;

                $customPlaylist = CustomPlaylist::find($targetCustomPlaylistId);
                if ($customPlaylist) {
                    $channel->customPlaylists()->syncWithoutDetaching([$customPlaylist->id]);
                }
            }
        }

        $previousTitle = trim((string) ($info['twitch_title'] ?? ''));
        if ($previousTitle === '') {
            $previousTitle = trim((string) ($stream['title'] ?? $channel->title ?? ''));
        }

        $newTitle = trim((string) ($stream['title'] ?? ''));
        $liveChannelTitle = $this->resolveLiveChannelTitle([
            'login' => (string) ($stream['login'] ?? data_get($info, 'twitch_login', '')),
            'display_name' => (string) ($stream['display_name'] ?? $channel->name ?? ''),
            'title' => $newTitle,
        ], $settings);
        $sanitizedTitle = $this->sanitizeXtreamText($liveChannelTitle, $settings);
        if ($sanitizedTitle && $sanitizedTitle !== $channel->title) {
            $channel->title = $sanitizedTitle;
            $changed = true;
        }

        $rawDisplayName = (string) ($stream['display_name'] ?? $channel->name ?? '');
        $sanitizedName = $this->sanitizeXtreamText($rawDisplayName, $settings);
        if ($sanitizedName && $sanitizedName !== $channel->name) {
            $channel->name = $sanitizedName;
            $changed = true;
        }

        if (($info['twitch_title'] ?? '') !== $newTitle) {
            $info['twitch_title'] = $newTitle;
            $changed = true;
        }

        if (! isset($info['twitch_stream_started'])) {
            $info['twitch_stream_started'] = $stream['started_at'] ?? Carbon::now()->toISOString();
            $changed = true;
        }

        // Initialize title history if missing (for channels created before title segmentation support)
        if (! isset($info['twitch_title_history'])) {
            $info['twitch_title_history'] = [[
                'title' => $previousTitle,
                'started_at' => $info['twitch_stream_started'] ?? Carbon::now()->toISOString(),
            ]];
            $changed = true;
        }

        $normalizedNewTitle = $newTitle;
        $titleHistory = $info['twitch_title_history'] ?? [];
        $lastTitle = '';
        if (! empty($titleHistory)) {
            $lastSegment = $titleHistory[array_key_last($titleHistory)] ?? [];
            $lastTitle = trim((string) ($lastSegment['title'] ?? ''));
        }

        if ($normalizedNewTitle !== '' && $normalizedNewTitle !== $lastTitle) {
            $titleHistory[] = [
                'title' => $normalizedNewTitle,
                'started_at' => Carbon::now()->toISOString(),
            ];

            if (count($titleHistory) > 50) {
                $titleHistory = array_slice($titleHistory, -50);
            }

            $info['twitch_title_history'] = $titleHistory;
            $changed = true;
        }

        $newGame = $stream['game'] ?? '';
        $oldGame = $info['twitch_game'] ?? '';

        // Initialize EPG history if missing (for channels created before EPG support)
        if (! isset($info['twitch_epg_history'])) {
            $info['twitch_epg_history'] = [[
                'game' => $oldGame,
                'game_box_art' => $info['twitch_game_box_art'] ?? '',
                'started_at' => Carbon::now()->toISOString(),
            ]];
            $changed = true;
        }

        if ($newGame !== $oldGame) {
            $info['twitch_game'] = $newGame;
            $info['twitch_game_box_art'] = $stream['game_box_art'] ?? '';

            // Append game change to EPG history
            $history = $info['twitch_epg_history'] ?? [];
            $history[] = [
                'game' => $newGame,
                'game_box_art' => $stream['game_box_art'] ?? '',
                'started_at' => Carbon::now()->toISOString(),
            ];

            if (count($history) > 50) {
                $history = array_slice($history, -50);
            }

            $info['twitch_epg_history'] = $history;
            $changed = true;
        }

        $login = strtolower($stream['login'] ?? '');
        $newLogo = $stream['profile_image'] ?: ($userProfiles[$login]['profile_image'] ?? '');
        if ($newLogo && $newLogo !== $channel->logo_internal) {
            $channel->logo_internal = $newLogo;
            $changed = true;
        }

        // Update language if available
        $newLang = $stream['language'] ?? '';
        if ($newLang !== '' && $newLang !== ($channel->lang ?? '')) {
            $channel->lang = $newLang;
            $changed = true;
        }

        $playlistId = (int) ($channel->playlist_id ?: ($settings['target_playlist_id'] ?? 0)) ?: null;
        $customPlaylistId = $playlistId
            ? null
            : ((int) ($channel->custom_playlist_id ?: ($settings['target_custom_playlist_id'] ?? 0)) ?: null);

        // Always ensure a valid group is assigned, including channels created before this fix.
        $expectedGroupName = $this->resolveGroupName([
            'game' => $newGame,
        ], self::STREAM_TYPE_LIVE, $settings);
        $expectedGroup = $this->resolveOrCreateGroup($expectedGroupName, $userId, $playlistId, $customPlaylistId);
        if ($expectedGroup && (int) $channel->group_id !== (int) $expectedGroup->id) {
            $channel->group_id = $expectedGroup->id;
            $changed = true;
        }

        // Ensure the group text fields are always set (required for channel overview display).
        if ($expectedGroupName && $channel->group !== $expectedGroupName) {
            $channel->group = $expectedGroupName;
            $channel->group_internal = $expectedGroupName;
            $changed = true;
        }

        // Update category tags for custom playlists when game mode changes category.
        $groupMode = $settings['group_mode'] ?? 'static';
        if ($groupMode === 'game' && $newGame !== $oldGame && $newGame !== '' && $customPlaylistId) {
            $customPlaylist = CustomPlaylist::find($customPlaylistId);
            if ($customPlaylist && ! empty($customPlaylist->uuid)) {
                if ($oldGame) {
                    $oldTag = $customPlaylist->groupTags()->where('name->en', $oldGame)->first();
                    if ($oldTag) {
                        $channel->detachTag($oldTag);
                    }
                }

                $newTag = $customPlaylist->groupTags()->where('name->en', $newGame)->first();
                if (! $newTag) {
                    $newTag = Tag::create(['name' => ['en' => $newGame], 'type' => $customPlaylist->uuid]);
                    $customPlaylist->attachTag($newTag);
                }
                $channel->attachTag($newTag);
            }
        }

        // Sync container_extension with current output_format setting.
        $expectedExtension = $this->resolveContainerExtension($settings, false);
        if ($channel->container_extension !== $expectedExtension) {
            $channel->container_extension = $expectedExtension;
            $changed = true;
        }

        if ($changed) {
            $channel->info = $info;
            $channel->save();
        }

        return $changed;
    }

    private function cleanupEndedStreams(
        array $settings,
        int $userId,
        ?string $cookiesFile,
        PluginExecutionContext $context,
    ): int {
        $cleaned = 0;
        $streamlink = null;

        // --- Cleanup ended Twitch live streams ---
        /** @var Collection<int, Channel> $channels */
        $channels = Channel::where('user_id', $userId)
            ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
            ->whereJsonContains('info->twitch_stream_type', self::STREAM_TYPE_LIVE)
            ->get();

        if ($channels->isNotEmpty()) {
            $useApi = $this->hasTwitchApiCredentials($settings) && $this->accessToken;

            if ($useApi) {
                // Batch check - very efficient
                $logins = $channels->map(fn (Channel $ch) => data_get($ch->info, 'twitch_login'))->filter()->unique()->values()->all();
                $liveStreams = $this->batchGetStreams($settings, $logins);
                $liveLogins = collect($liveStreams)->pluck('login')->map(fn ($l) => strtolower($l))->all();

                foreach ($channels as $channel) {
                    $login = strtolower(data_get($channel->info, 'twitch_login', ''));

                    if (! in_array($login, $liveLogins, true)) {
                        $context->info("Stream ended for {$login} (channel #{$channel->channel} '{$channel->title}') - removing.");
                        $channel->delete();
                        $cleaned++;
                    }
                }
            } else {
                $streamlink = $streamlink ?? $this->findStreamlink();
                if (! $streamlink) {
                    $context->warning('Cannot check Twitch stream status - streamlink not found.');
                } else {
                    foreach ($channels as $channel) {
                        $login = data_get($channel->info, 'twitch_login', '');
                        if (! $login) {
                            continue;
                        }

                        $streamInfo = $this->checkChannelLiveViaStreamlink($streamlink, $login, $cookiesFile);

                        if (! $streamInfo) {
                            $context->info("Stream ended for {$login} (channel #{$channel->channel} '{$channel->title}') - removing.");
                            $channel->delete();
                            $cleaned++;
                        }
                    }
                }
            }
        }

        // --- Cleanup ended YouTube live streams ---
        // When a YouTube Data API key is configured, route the re-check through
        // YouTubeProvider::detectLive() so already-live channels use the same
        // batched API path as discovery (and consume the same quota budget).
        // Otherwise fall back to the streamlink probe.
        /** @var Collection<int, Channel> $ytChannels */
        $ytChannels = Channel::where('user_id', $userId)
            ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
            ->whereJsonContains('info->youtube_stream_type', self::STREAM_TYPE_LIVE)
            ->get();

        if ($ytChannels->isNotEmpty()) {
            $apiKey = trim((string) ($settings['youtube_api_key'] ?? ''));
            $apiResults = [];
            $fallbackUrls = [];

            if ($apiKey !== '') {
                $ytProvider = new Providers\YouTubeProvider();
                $entries = [];
                foreach ($ytChannels as $channel) {
                    $url = (string) data_get($channel->info, 'youtube_monitored_url', '');
                    if ($url === '') {
                        continue;
                    }
                    $entry = $ytProvider->parseEntry($url);
                    if ($entry) {
                        $entries[] = $entry;
                    }
                }
                if (! empty($entries)) {
                    try {
                        $apiResults = $ytProvider->detectLive($entries, $settings, $cookiesFile);
                        $fallbackUrls = $ytProvider->getPendingFallback();
                    } catch (\Throwable $e) {
                        $context->warning("YouTube cleanup API call failed, falling back to streamlink: {$e->getMessage()}");
                        $apiResults = [];
                        $fallbackUrls = [];
                    }
                }
            }

            // Resolve streamlink once. We almost always need it: even when an API
            // key is set we still confirm "offline-per-API" results via streamlink
            // before deleting (so a quota blip doesn't wipe live channels).
            $streamlink = $streamlink ?? $this->findStreamlink();
            if (! $streamlink) {
                $context->warning('Cannot check YouTube stream status - streamlink not found.');
            }

            foreach ($ytChannels as $channel) {
                $ytUrl = (string) data_get($channel->info, 'youtube_monitored_url', '');
                if ($ytUrl === '') {
                    continue;
                }

                // Decide whether this URL was answered by the API. A URL is
                // "API-answered" when a key is configured AND it did not land in
                // the fallback bucket. apiResults only contains live entries,
                // so absence means offline-per-API.
                $apiAnsweredOffline = $apiKey !== ''
                    && ! in_array($ytUrl, $fallbackUrls, true)
                    && ! array_key_exists($ytUrl, $apiResults);

                $info = $apiResults[$ytUrl] ?? null;

                // Streamlink probe runs when:
                //   - no API key (or every URL fell back), or
                //   - this URL fell back, or
                //   - API said offline (be conservative: confirm before
                //     deleting so a transient quota error / 5xx doesn't
                //     wipe a still-live channel).
                if ($info === null && isset($streamlink) && $streamlink) {
                    $info = $this->checkYouTubeLiveViaStreamlink($streamlink, $ytUrl, $cookiesFile);
                }

                if (! $info) {
                    if ($apiAnsweredOffline && (! isset($streamlink) || ! $streamlink)) {
                        // API said offline, no streamlink to confirm: keep the
                        // channel rather than risk deleting a live one.
                        continue;
                    }
                    $context->info("YouTube stream ended: {$ytUrl} (channel #{$channel->channel} '{$channel->title}') - removing.");
                    $channel->delete();
                    $cleaned++;
                }
            }
        }

        // --- Cleanup ended generic streamlink streams (Kick, Vimeo, …) ---
        /** @var Collection<int, Channel> $genericChannels */
        $genericChannels = Channel::where('user_id', $userId)
            ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
            ->whereJsonContains('info->streamlink_stream_type', self::STREAM_TYPE_LIVE)
            ->get();

        if ($genericChannels->isNotEmpty()) {
            $streamlink = $streamlink ?? $this->findStreamlink();

            if (! $streamlink) {
                $context->warning('Cannot check generic stream status - streamlink not found.');
            } else {
                foreach ($genericChannels as $channel) {
                    $url = data_get($channel->info, 'streamlink_monitored_url', '');
                    if (! $url) {
                        continue;
                    }

                    $info = $this->checkYouTubeLiveViaStreamlink($streamlink, $url, $cookiesFile);

                    if (! $info) {
                        $context->info("Stream ended: {$url} (channel #{$channel->channel} '{$channel->title}') - removing.");
                        $channel->delete();
                        $cleaned++;
                    }
                }
            }
        }

        return $cleaned;
    }

    /**
     * Create or update a channel for a live YouTube stream.
     *
     * @param  array{url: string, title: string, author: string, category: string, id: string}  $ytInfo
     * @return bool True if a new channel was created, false if an existing one was updated.
     */
    private function createOrUpdateYouTubeChannel(
        array $ytInfo,
        array $settings,
        int $userId,
        PluginExecutionContext $context,
    ): bool {
        $monitoredUrl = $ytInfo['url'];
        $group = $this->resolveGroupForPlatform('youtube', $settings);

        /** @var Channel|null $existing */
        $existing = Channel::where('user_id', $userId)
            ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
            ->whereJsonContains('info->youtube_monitored_url', $monitoredUrl)
            ->whereJsonContains('info->youtube_stream_type', self::STREAM_TYPE_LIVE)
            ->first();

        $title = $ytInfo['title'] ?: ($ytInfo['author'] ?: 'YouTube Live');

        if ($existing) {
            $info = $existing->info ?? [];
            $info['youtube_title'] = $ytInfo['title'];
            $info['youtube_author'] = $ytInfo['author'];
            $info['youtube_category'] = $ytInfo['category'];

            $existing->title = $title;
            $existing->info = $info;
            $existing->save();

            $context->info("YouTube updated: {$monitoredUrl}. '{$title}'");

            return false;
        }

        // Resolve playlist target: regular playlist takes precedence; otherwise
        // fall back to custom_playlist (tagging instead of group_id).
        $playlistId = (int) ($settings['target_playlist_id'] ?? 0) ?: null;
        $customPlaylistId = (int) ($settings['target_custom_playlist_id'] ?? 0) ?: null;

        if (! $playlistId && ! $customPlaylistId) {
            $context->error("YouTube cannot create channel for {$monitoredUrl}: no target_playlist_id or target_custom_playlist_id configured.");

            return false;
        }

        $groupModel = $this->resolveOrCreateGroup($group, $userId, $playlistId, $customPlaylistId);
        [$customPlaylist, $groupTag] = $this->resolveCustomPlaylistGroupTag($customPlaylistId, $group);

        $channelNumber = $this->nextChannelNumber($userId, $settings, 'youtube', null);

        $info = [
            'plugin' => self::PLUGIN_MARKER,
            'youtube_stream_type' => self::STREAM_TYPE_LIVE,
            'youtube_monitored_url' => $monitoredUrl,
            'youtube_title' => $ytInfo['title'],
            'youtube_author' => $ytInfo['author'],
            'youtube_category' => $ytInfo['category'],
            'youtube_id' => $ytInfo['id'],
        ];

        // Resolve the stream URL for the channel (use the monitored URL directly;
        // m3u-proxy / streamlink will resolve the actual HLS stream on connection).
        $streamUrl = $monitoredUrl;

        $channel = Channel::create([
            'user_id' => $userId,
            'title' => $title,
            'name' => $title,
            'url' => $streamUrl,
            'group_id' => $groupModel?->id,
            'playlist_id' => $playlistId,
            'custom_playlist_id' => $playlistId ? null : $customPlaylistId,
            'channel' => $channelNumber,
            'enabled' => true,
            'info' => $info,
        ]);

        if (! $playlistId && $customPlaylist) {
            $channel->customPlaylists()->syncWithoutDetaching([$customPlaylist->id]);
            if ($groupTag) {
                $channel->attachTag($groupTag);
            }
        }

        $context->info("YouTube added: {$monitoredUrl}. '{$title}' (ch #{$channel->channel})");

        return true;
    }

    /**
     * Create or update a channel for any non-Twitch / non-YouTube platform that
     * streamlink can resolve (Kick, Vimeo, BiliBili, Rumble, NicoNico, SOOP,
     * DLive, AfreecaTV, Mildom, …). Mirrors createOrUpdateYouTubeChannel. the
     * monitored URL is the stable identity, the resolved HLS stream is computed
     * by m3u-proxy / streamlink at playback time.
     *
     * @param  array{url:string,title:string,author:string,category:string,id:string}  $info
     */
    private function createOrUpdateGenericChannel(
        array $info,
        array $settings,
        int $userId,
        PluginExecutionContext $context,
        ?string $platformHint = null,
    ): bool {
        $monitoredUrl = $info['url'];
        $platform = $platformHint
            ? ucfirst($platformHint)
            : $this->detectPlatformLabel($monitoredUrl);
        $platformId = $platformHint ?: strtolower($this->detectPlatformLabel($monitoredUrl));
        $group = $this->resolveGroupForPlatform($platformId, $settings);

        /** @var Channel|null $existing */
        $existing = Channel::where('user_id', $userId)
            ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
            ->whereJsonContains('info->streamlink_monitored_url', $monitoredUrl)
            ->whereJsonContains('info->streamlink_stream_type', self::STREAM_TYPE_LIVE)
            ->first();

        $title = $info['title'] ?: ($info['author'] ?: $platform.' Live');

        if ($existing) {
            $infoCol = $existing->info ?? [];
            $infoCol['streamlink_title'] = $info['title'];
            $infoCol['streamlink_author'] = $info['author'];
            $infoCol['streamlink_category'] = $info['category'];

            $existing->title = $title;
            $existing->info = $infoCol;
            $existing->save();

            $context->info("{$platform} updated: {$monitoredUrl}. '{$title}'");

            return false;
        }

        $playlistId = (int) ($settings['target_playlist_id'] ?? 0) ?: null;
        $customPlaylistId = (int) ($settings['target_custom_playlist_id'] ?? 0) ?: null;

        if (! $playlistId && ! $customPlaylistId) {
            $context->error("{$platform} cannot create channel for {$monitoredUrl}: no target_playlist_id or target_custom_playlist_id configured.");

            return false;
        }

        $groupModel = $this->resolveOrCreateGroup($group, $userId, $playlistId, $customPlaylistId);
        [$customPlaylist, $groupTag] = $this->resolveCustomPlaylistGroupTag($customPlaylistId, $group);

        $channelNumber = $this->nextChannelNumber($userId, $settings, strtolower($platform), null);

        $infoCol = [
            'plugin' => self::PLUGIN_MARKER,
            'streamlink_stream_type' => self::STREAM_TYPE_LIVE,
            'streamlink_monitored_url' => $monitoredUrl,
            'streamlink_platform' => $platform,
            'streamlink_title' => $info['title'],
            'streamlink_author' => $info['author'],
            'streamlink_category' => $info['category'],
            'streamlink_id' => $info['id'],
        ];

        $channel = Channel::create([
            'user_id' => $userId,
            'title' => $title,
            'name' => $title,
            'url' => $monitoredUrl,
            'group_id' => $groupModel?->id,
            'playlist_id' => $playlistId,
            'custom_playlist_id' => $playlistId ? null : $customPlaylistId,
            'channel' => $channelNumber,
            'enabled' => true,
            'info' => $infoCol,
        ]);

        if (! $playlistId && $customPlaylist) {
            $channel->customPlaylists()->syncWithoutDetaching([$customPlaylist->id]);
            if ($groupTag) {
                $channel->attachTag($groupTag);
            }
        }

        $context->info("{$platform} added: {$monitoredUrl}. '{$title}' (ch #{$channel->channel})");

        return true;
    }

    /**
     * Persist a Kick VOD as its own Channel row. Mirrors the Twitch VOD
     * pattern but uses the streamlink_* / kick_* info keys so cleanup and
     * grouping logic treats it as a generic VOD, not a Twitch VOD.
     */
    private function createOrUpdateKickVod(
        \AppLocalPlugins\Streamarr\Providers\DTO\VodInfo $vod,
        \AppLocalPlugins\Streamarr\Providers\DTO\MonitoredEntry $entry,
        array $settings,
        int $userId,
        PluginExecutionContext $context,
    ): bool {
        $platformId = 'kick';
        $platformLabel = 'Kick';

        /** @var Channel|null $existing */
        $existing = Channel::where('user_id', $userId)
            ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
            ->whereJsonContains('info->kick_vod_id', $vod->vodId)
            ->first();

        if ($existing) {
            return false;
        }

        $vodGroupOverride = trim((string) ($settings['kick_vod_group'] ?? ''));
        $groupName = $vodGroupOverride !== ''
            ? $vodGroupOverride
            : ($this->resolveGroupForPlatform($platformId, $settings).' VODs');

        $playlistId = (int) ($settings['target_playlist_id'] ?? 0) ?: null;
        $customPlaylistId = (int) ($settings['target_custom_playlist_id'] ?? 0) ?: null;

        if (! $playlistId && ! $customPlaylistId) {
            $context->error("{$platformLabel} VOD cannot create channel for {$entry->raw}: no target_playlist_id or target_custom_playlist_id configured.");

            return false;
        }

        $groupModel = $this->resolveOrCreateGroup($groupName, $userId, $playlistId, $customPlaylistId);
        [$customPlaylist, $groupTag] = $this->resolveCustomPlaylistGroupTag($customPlaylistId, $groupName);

        $channelNumber = $this->nextChannelNumber($userId, $settings, $platformId.'-vod-'.$vod->vodId, null);
        $title = $vod->title !== '' ? $vod->title : ($entry->label.' - VOD');

        $infoCol = [
            'plugin' => self::PLUGIN_MARKER,
            'streamlink_stream_type' => self::STREAM_TYPE_VOD,
            'streamlink_monitored_url' => $vod->url,
            'streamlink_platform' => $platformLabel,
            'streamlink_title' => $title,
            'streamlink_author' => $entry->label,
            'streamlink_category' => $vod->category ?? '',
            'streamlink_id' => $vod->vodId,
            'kick_vod_id' => $vod->vodId,
            'kick_slug' => $entry->extras['slug'] ?? $entry->label,
            'kick_vod_published_at' => $vod->publishedAt,
            'kick_vod_duration_secs' => $vod->durationSeconds,
            'kick_vod_thumbnail' => $vod->thumbnailUrl,
        ];

        $channel = Channel::create([
            'user_id' => $userId,
            'title' => $title,
            'name' => $title,
            'url' => $vod->url,
            'group_id' => $groupModel?->id,
            'playlist_id' => $playlistId,
            'custom_playlist_id' => $playlistId ? null : $customPlaylistId,
            'channel' => $channelNumber,
            'enabled' => true,
            'is_vod' => true,
            'info' => $infoCol,
            'logo_internal' => $vod->thumbnailUrl ?? '',
        ]);

        if (! $playlistId && $customPlaylist) {
            $channel->customPlaylists()->syncWithoutDetaching([$customPlaylist->id]);
            if ($groupTag) {
                $channel->attachTag($groupTag);
            }
        }

        $context->info("Kick VOD added: {$entry->label} - '{$title}' (ch #{$channel->channel})");

        return true;
    }

    /**
     * Best-effort human-readable platform label from a stream URL.
     */
    private function detectPlatformLabel(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        $map = [
            'kick.com' => 'Kick',
            'vimeo.com' => 'Vimeo',
            'live.bilibili.com' => 'BiliBili',
            'bilibili.com' => 'BiliBili',
            'rumble.com' => 'Rumble',
            'nicovideo.jp' => 'NicoNico',
            'live.nicovideo.jp' => 'NicoNico',
            'sooplive.co.kr' => 'SOOP',
            'dlive.tv' => 'DLive',
            'afreecatv.com' => 'AfreecaTV',
            'play.afreecatv.com' => 'AfreecaTV',
            'mildom.com' => 'Mildom',
            'trovo.live' => 'Trovo',
            'huya.com' => 'Huya',
            'douyu.com' => 'Douyu',
            'ok.ru' => 'OK.ru',
        ];

        if (isset($map[$host])) {
            return $map[$host];
        }

        // Fallback: derive label from the registrable domain.
        $parts = explode('.', $host);
        if (count($parts) >= 2) {
            return ucfirst($parts[count($parts) - 2]);
        }

        return 'Live';
    }

    // -------------------------------------------------------------------------
    // Channel numbering
    // -------------------------------------------------------------------------

    private function nextChannelNumber(int $userId, array $settings, string $login, ?int $baseNumber): int|float
    {
        $mode = $settings['channel_numbering_mode'] ?? 'sequential';
        $increment = (int) ($settings['channel_number_increment'] ?? 1);
        $starting = (int) ($settings['starting_channel_number'] ?? 3000);
        $xtreamCompat = $this->isXtreamCompatibilityModeEnabled($settings);
        $xtreamMin = max(1, (int) ($settings['xtream_min_channel_number'] ?? 900));
        $xtreamMax = max($xtreamMin, (int) ($settings['xtream_max_channel_number'] ?? 999));

        if ($mode === 'decimal' && $baseNumber !== null) {
            $sibling = Channel::where('user_id', $userId)
                ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
                ->whereJsonContains('info->twitch_login', strtolower($login))
                ->orderByDesc('channel')
                ->first();

            if ($sibling) {
                $current = (float) $sibling->channel;
                $decimal = fmod($current, 1.0);
                $sub = (int) round($decimal * 10) + 1;

                return round($baseNumber + ($sub / 10), 1);
            }

            return round($baseNumber + 0.1, 1);
        }

        $last = Channel::where('user_id', $userId)
            ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
            ->orderByDesc('channel')
            ->value('channel');

        if ($last === null) {
            if ($xtreamCompat && ($starting < $xtreamMin || $starting > $xtreamMax)) {
                $fallback = $this->findNextFreeChannelNumber($userId, $xtreamMin, $xtreamMax);
                if ($fallback !== null) {
                    return $fallback;
                }
            }

            return $starting;
        }

        $candidate = (int) $last + $increment;

        if ($xtreamCompat && ($candidate < $xtreamMin || $candidate > $xtreamMax)) {
            $fallback = $this->findNextFreeChannelNumber($userId, $xtreamMin, $xtreamMax);
            if ($fallback !== null) {
                return $fallback;
            }
        }

        return $candidate;
    }

    private function isXtreamCompatibilityModeEnabled(array $settings): bool
    {
        return (bool) ($settings['xtream_compat_mode'] ?? true);
    }

    private function findNextFreeChannelNumber(int $userId, int $min, int $max, ?int $excludeChannelId = null): ?int
    {
        $query = Channel::where('user_id', $userId)
            ->whereNotNull('channel')
            ->where('channel', '>=', $min)
            ->where('channel', '<=', $max);

        if ($excludeChannelId) {
            $query->where('id', '!=', $excludeChannelId);
        }

        $used = [];
        foreach ($query->pluck('channel')->all() as $value) {
            $used[(int) floor((float) $value)] = true;
        }

        for ($candidate = $min; $candidate <= $max; $candidate++) {
            if (! isset($used[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Twitch Helix API
    // -------------------------------------------------------------------------

    private function hasTwitchApiCredentials(array $settings): bool
    {
        return ! empty($settings['twitch_client_id']) && ! empty($settings['twitch_client_secret']);
    }

    private function twitchApiRequest(array $settings)
    {
        return Http::retry(2, 250, throw: false)
            ->timeout(20)
            ->withHeaders([
                'Client-ID' => $settings['twitch_client_id'],
                'Authorization' => "Bearer {$this->accessToken}",
            ]);
    }

    private function twitchTokenRequest()
    {
        return Http::retry(2, 250, throw: false)
            ->timeout(20)
            ->asForm();
    }

    /**
     * Obtain an App Access Token via client_credentials grant.
     */
    private function getAppAccessToken(array $settings): ?string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $response = $this->twitchTokenRequest()->post('https://id.twitch.tv/oauth2/token', [
            'client_id' => $settings['twitch_client_id'],
            'client_secret' => $settings['twitch_client_secret'],
            'grant_type' => 'client_credentials',
        ]);

        if (! $response->successful()) {
            return null;
        }

        return $response->json('access_token');
    }

    /**
     * Batch-fetch user profiles from Twitch Helix API.
     * Returns map: lowercase login → {user_id, display_name, profile_image, login}
     *
     * @param  list<string>  $logins
     * @return array<string, array{user_id: string, display_name: string, profile_image: string, login: string}>
     */
    private function batchGetUsers(array $settings, array $logins): array
    {
        $results = [];

        foreach (array_chunk($logins, 100) as $chunk) {
            $query = collect($chunk)->map(fn ($l) => "login={$l}")->implode('&');

            $response = $this->twitchApiRequest($settings)
                ->get("https://api.twitch.tv/helix/users?{$query}");

            if (! $response->successful()) {
                continue;
            }

            foreach ($response->json('data', []) as $user) {
                $login = strtolower($user['login'] ?? '');
                if ($login) {
                    // Downsize to 70x70. sufficient for channel logos and saves bandwidth
                    $profileImage = $user['profile_image_url'] ?? '';
                    if ($profileImage !== '') {
                        $profileImage = preg_replace('#-\d+x\d+\.#', '-70x70.', $profileImage);
                    }

                    $results[$login] = [
                        'user_id' => (string) ($user['id'] ?? ''),
                        'display_name' => $user['display_name'] ?? $login,
                        'profile_image' => $profileImage,
                        'login' => $login,
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Batch-fetch live streams from Twitch Helix API.
     * Returns flat list of live stream data.
     *
     * @param  list<string>  $logins
    * @return list<array{login: string, display_name: string, user_id: string, title: string, game: string, game_box_art: string, thumbnail: string, profile_image: string, language: string, started_at: string}>
     */
    private function batchGetStreams(array $settings, array $logins): array
    {
        $results = [];

        foreach (array_chunk($logins, 100) as $chunk) {
            $query = collect($chunk)->map(fn ($l) => "user_login={$l}")->implode('&');

            $response = $this->twitchApiRequest($settings)
                ->get("https://api.twitch.tv/helix/streams?{$query}");

            if (! $response->successful()) {
                continue;
            }

            foreach ($response->json('data', []) as $stream) {
                if (($stream['type'] ?? '') !== 'live') {
                    continue;
                }

                $login = strtolower($stream['user_login'] ?? '');
                $thumbnail = str_replace(['{width}', '{height}'], ['640', '360'], $stream['thumbnail_url'] ?? '');

                $gameBoxArt = '';
                $gameId = $stream['game_id'] ?? '';
                if ($gameId) {
                    $gameBoxArt = "https://static-cdn.jtvnw.net/ttv-boxart/{$gameId}-144x192.jpg";
                }

                $results[] = [
                    'login' => $login,
                    'display_name' => $stream['user_name'] ?? $login,
                    'user_id' => (string) ($stream['user_id'] ?? ''),
                    'title' => $stream['title'] ?? '',
                    'game' => $stream['game_name'] ?? '',
                    'game_box_art' => $gameBoxArt,
                    'thumbnail' => $thumbnail,
                    'profile_image' => '',
                    'language' => $stream['language'] ?? '',
                    'started_at' => $stream['started_at'] ?? Carbon::now()->toISOString(),
                ];
            }
        }

        return $results;
    }

    /**
     * Fetch recent VODs for a Twitch user via Helix API.
     *
     * @return list<array{id: string, stream_id: string, title: string, description: string, created_at: string, published_at: string, url: string, thumbnail: string, view_count: int, language: string, vod_type: string, duration_raw: string, duration_secs: int, duration_formatted: string, episode_run_time: int, muted_segments: list<array{duration: int, offset: int}>}>
     */
    private function getChannelVideos(array $settings, string $twitchUserId, int $limit): array
    {
        $response = $this->twitchApiRequest($settings)->get("https://api.twitch.tv/helix/videos", [
            'user_id' => $twitchUserId,
            'type' => 'archive',
            'first' => min($limit, 100),
        ]);

        if (! $response->successful()) {
            return [];
        }

        $results = [];

        foreach ($response->json('data', []) as $video) {
            $thumbnail = str_replace(['%{width}', '%{height}'], ['640', '360'], $video['thumbnail_url'] ?? '');
            $durationRaw = $video['duration'] ?? '';
            $durationSecs = $this->parseTwitchDuration($durationRaw);

            $results[] = [
                'id' => (string) ($video['id'] ?? ''),
                'stream_id' => (string) ($video['stream_id'] ?? ''),
                'title' => $video['title'] ?? 'Untitled VOD',
                'description' => $video['description'] ?? '',
                'created_at' => $video['created_at'] ?? '',
                'published_at' => $video['published_at'] ?? '',
                'url' => $video['url'] ?? '',
                'thumbnail' => $thumbnail,
                'view_count' => (int) ($video['view_count'] ?? 0),
                'language' => $video['language'] ?? '',
                'vod_type' => $video['type'] ?? 'archive',
                'duration_raw' => $durationRaw,
                'duration_secs' => $durationSecs,
                'duration_formatted' => sprintf('%02d:%02d:%02d', intdiv($durationSecs, 3600), intdiv($durationSecs % 3600, 60), $durationSecs % 60),
                'episode_run_time' => (int) ceil($durationSecs / 60),
                'muted_segments' => array_map(
                    fn (array $seg) => ['duration' => (int) ($seg['duration'] ?? 0), 'offset' => (int) ($seg['offset'] ?? 0)],
                    $video['muted_segments'] ?? [],
                ),
            ];
        }

        return $results;
    }

    /**
     * Fetch a single video by ID from Twitch Helix API.
     * Used when manually adding a VOD URL to get full metadata.
     *
     * @return array{id: string, stream_id: string, user_id: string, user_login: string, user_name: string, title: string, description: string, created_at: string, published_at: string, url: string, thumbnail: string, view_count: int, language: string, vod_type: string, duration_raw: string, duration_secs: int, duration_formatted: string, episode_run_time: int, muted_segments: list<array{duration: int, offset: int}>}|null
     */
    private function getVideoById(array $settings, string $videoId): ?array
    {
        $response = $this->twitchApiRequest($settings)->get('https://api.twitch.tv/helix/videos', [
            'id' => $videoId,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $video = $response->json('data.0');
        if (! $video) {
            return null;
        }

        $thumbnail = str_replace(['%{width}', '%{height}'], ['640', '360'], $video['thumbnail_url'] ?? '');
        $durationRaw = $video['duration'] ?? '';
        $durationSecs = $this->parseTwitchDuration($durationRaw);

        return [
            'id' => (string) ($video['id'] ?? ''),
            'stream_id' => (string) ($video['stream_id'] ?? ''),
            'user_id' => (string) ($video['user_id'] ?? ''),
            'user_login' => strtolower($video['user_login'] ?? ''),
            'user_name' => $video['user_name'] ?? '',
            'title' => $video['title'] ?? 'Untitled VOD',
            'description' => $video['description'] ?? '',
            'created_at' => $video['created_at'] ?? '',
            'published_at' => $video['published_at'] ?? '',
            'url' => $video['url'] ?? '',
            'thumbnail' => $thumbnail,
            'view_count' => (int) ($video['view_count'] ?? 0),
            'language' => $video['language'] ?? '',
            'vod_type' => $video['type'] ?? 'archive',
            'duration_raw' => $durationRaw,
            'duration_secs' => $durationSecs,
            'duration_formatted' => sprintf('%02d:%02d:%02d', intdiv($durationSecs, 3600), intdiv($durationSecs % 3600, 60), $durationSecs % 60),
            'episode_run_time' => (int) ceil($durationSecs / 60),
            'muted_segments' => array_map(
                fn (array $seg) => ['duration' => (int) ($seg['duration'] ?? 0), 'offset' => (int) ($seg['offset'] ?? 0)],
                $video['muted_segments'] ?? [],
            ),
        ];
    }

    // -------------------------------------------------------------------------
    // Streamlink fallback
    // -------------------------------------------------------------------------

    /**
     * Check if a Twitch channel is live using streamlink --json.
     *
    * @return array{login: string, display_name: string, user_id: string, title: string, game: string, game_box_art: string, thumbnail: string, profile_image: string, language: string, started_at: string}|null
     */
    private function checkChannelLiveViaStreamlink(string $binary, string $login, ?string $cookiesFile): ?array
    {
        $url = "https://www.twitch.tv/{$login}";
        $cmd = [$binary, '--json', $url];

        if ($cookiesFile && is_file($cookiesFile)) {
            $cmd[] = '--http-cookies-file';
            $cmd[] = $cookiesFile;
        }

        $result = $this->runProcess($cmd, 30);

        if ($result['exit'] !== 0 || empty($result['stdout'])) {
            return null;
        }

        $json = json_decode($result['stdout'], true);
        if (! is_array($json)) {
            return null;
        }

        // streamlink --json returns a "streams" object if channel is live, "error" if offline
        if (isset($json['error'])) {
            return null;
        }

        $metadata = $json['metadata'] ?? [];

        return [
            'login' => strtolower($login),
            'display_name' => $metadata['author'] ?? $login,
            'user_id' => '',
            'title' => $metadata['title'] ?? "{$login} - Live",
            'game' => $metadata['category'] ?? '',
            'game_box_art' => '',
            'thumbnail' => '',
            'profile_image' => '',
            'language' => '',
            'started_at' => Carbon::now()->toISOString(),
        ];
    }

    private function findStreamlink(): ?string
    {
        $candidates = ['streamlink', '/usr/local/bin/streamlink', '/usr/bin/streamlink', '/opt/venv/bin/streamlink'];

        foreach ($candidates as $candidate) {
            $result = $this->runProcess(['which', $candidate], 5);
            if ($result['exit'] === 0 && trim($result['stdout'])) {
                return trim($result['stdout']);
            }

            if (file_exists($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Check if a YouTube URL is live using streamlink --json.
     * Cookies are passed via --http-cookies-file when a Netscape cookie file is provided.
     *
     * @return array{url: string, title: string, author: string, category: string, id: string}|null
     */
    private function checkYouTubeLiveViaStreamlink(string $binary, string $url, ?string $cookiesFile): ?array
    {
        $cmd = [$binary, '--json', $url];

        if ($cookiesFile && is_file($cookiesFile)) {
            $cmd[] = '--http-cookies-file';
            $cmd[] = $cookiesFile;
        }

        $result = $this->runProcess($cmd, 30);

        if ($result['exit'] !== 0 || empty($result['stdout'])) {
            return null;
        }

        $json = json_decode($result['stdout'], true);
        if (! is_array($json)) {
            return null;
        }

        // streamlink --json returns {"error": "."} when not live / not found
        if (isset($json['error'])) {
            return null;
        }

        $metadata = $json['metadata'] ?? [];

        return [
            'url' => $url,
            'title' => $metadata['title'] ?? '',
            'author' => $metadata['author'] ?? '',
            'category' => $metadata['category'] ?? '',
            'id' => $metadata['id'] ?? '',
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Parse Twitch ISO 8601 duration (e.g. "3h24m10s") into total seconds.
     */
    private function parseTwitchDuration(string $duration): int
    {
        $duration = trim($duration);
        if ($duration === '') {
            return 0;
        }

        if (preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/i', $duration, $m)) {
            $hours = isset($m[1]) ? (int) $m[1] : 0;
            $minutes = isset($m[2]) ? (int) $m[2] : 0;
            $secs = isset($m[3]) ? (int) $m[3] : 0;

            return ($hours * 3600) + ($minutes * 60) + $secs;
        }

        $seconds = 0;

        if (preg_match('/(\d+)h/', $duration, $m)) {
            $seconds += (int) $m[1] * 3600;
        }

        if (preg_match('/(\d+)m/', $duration, $m)) {
            $seconds += (int) $m[1] * 60;
        }

        if (preg_match('/(\d+)s/', $duration, $m)) {
            $seconds += (int) $m[1];
        }

        return $seconds;
    }

    /**
     * Fetch a Twitch user's profile image URL by parsing the channel page HTML.
     * Used as a fallback when Twitch API credentials are not configured.
     */
    private function fetchProfileImageFallback(string $login): string
    {
        try {
            $response = Http::timeout(10)->get("https://www.twitch.tv/{$login}");

            if (! $response->successful()) {
                return '';
            }

            $html = $response->body();

            // Best match: Twitch profile image URL (contains "profile_image" in the CDN path)
            if (preg_match('#https://static-cdn\.jtvnw\.net/jtv_user_pictures/[^"\'\s]+profile_image[^"\'\s]*#', $html, $m)) {
                // Downsize to 70x70. sufficient for channel logos and saves bandwidth
                return preg_replace('#-\d+x\d+\.#', '-70x70.', $m[0]);
            }

            // Fallback: og:image meta tag (may be stream preview when live)
            if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) {
                return $m[1];
            }

            if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/', $html, $m)) {
                return $m[1];
            }

            return '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Determine the group name for a channel based on group_mode, stream type, and metadata.
     */
    private function resolveGroupName(array $metadata, string $streamType, array $settings): string
    {
        if ($streamType === self::STREAM_TYPE_VOD) {
            $vodGroup = trim((string) ($settings['vod_group'] ?? ''));

            return $vodGroup !== '' ? $vodGroup : 'Twitch VODs';
        }

        $groupMode = $settings['group_mode'] ?? 'static';

        if ($groupMode === 'game' && ! empty($metadata['game'])) {
            return trim((string) $metadata['game']);
        }

        $liveGroup = trim((string) ($settings['twitch_group'] ?? ''));
        if ($liveGroup === '') {
            $liveGroup = trim((string) ($settings['channel_group'] ?? ''));
        }

        return $liveGroup !== '' ? $liveGroup : 'Twitch Live';
    }

    /**
     * Heal group assignments for all existing streamarr channels.
     *
     * Runs at the start of every Check Now cycle so that channels whose streams
     * are currently offline also receive the correct playlist-scoped group.
     * Without this, offline channels retain their old (null-playlist) group and
     * do not appear as a category in playlist-scoped Xtream views.
     */
    private function healGroupAssignments(array $settings, int $userId, PluginExecutionContext $context): void
    {
        $targetPlaylistId = (int) ($settings['target_playlist_id'] ?? 0) ?: null;
        $targetCustomPlaylistId = (int) ($settings['target_custom_playlist_id'] ?? 0) ?: null;

        if (! $targetPlaylistId && ! $targetCustomPlaylistId) {
            return;
        }

        $channels = Channel::where('user_id', $userId)
            ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
            ->get();

        $healed = 0;

        foreach ($channels as $channel) {
            $channelChanged = false;

            // Backfill missing playlist assignment.
            if (! $channel->playlist_id && ! $channel->custom_playlist_id) {
                if ($targetPlaylistId) {
                    $channel->playlist_id = $targetPlaylistId;
                    $channel->custom_playlist_id = null;
                    $channelChanged = true;
                } elseif ($targetCustomPlaylistId) {
                    $channel->custom_playlist_id = $targetCustomPlaylistId;
                    $channelChanged = true;

                    $customPlaylist = CustomPlaylist::find($targetCustomPlaylistId);
                    if ($customPlaylist) {
                        $channel->customPlaylists()->syncWithoutDetaching([$customPlaylist->id]);
                    }
                }
            }

            // Resolve the current playlist scope (prefer the channel's own value, fall back to settings).
            $playlistId = (int) ($channel->playlist_id ?: ($settings['target_playlist_id'] ?? 0)) ?: null;
            $customPlaylistId = $playlistId
                ? null
                : ((int) ($channel->custom_playlist_id ?: ($settings['target_custom_playlist_id'] ?? 0)) ?: null);

            if (! $playlistId && ! $customPlaylistId) {
                continue;
            }

            $streamType = ($channel->info['twitch_stream_type'] ?? self::STREAM_TYPE_LIVE);
            $game = $channel->info['twitch_game'] ?? '';
            $expectedGroupName = $this->resolveGroupName(['game' => $game], $streamType, $settings);
            $expectedGroup = $this->resolveOrCreateGroup($expectedGroupName, $userId, $playlistId, $customPlaylistId);

            if ($expectedGroup && (int) $channel->group_id !== (int) $expectedGroup->id) {
                $channel->group_id = $expectedGroup->id;
                $channelChanged = true;
            }

            // Ensure the group text fields are always set (required for channel overview display).
            if ($expectedGroupName && $channel->group !== $expectedGroupName) {
                $channel->group = $expectedGroupName;
                $channel->group_internal = $expectedGroupName;
                $channelChanged = true;
            }

            if ($channelChanged) {
                $channel->save();
                $healed++;
            }
        }

        if ($healed > 0) {
            $context->info("Healed group assignments for {$healed} channel(s).");
        }
    }

    private function healXtreamCompatibilityNumbers(array $settings, int $userId, PluginExecutionContext $context): void
    {
        if (! $this->isXtreamCompatibilityModeEnabled($settings)) {
            return;
        }

        if (($settings['channel_numbering_mode'] ?? 'sequential') !== 'sequential') {
            return;
        }

        $min = max(1, (int) ($settings['xtream_min_channel_number'] ?? 900));
        $max = max($min, (int) ($settings['xtream_max_channel_number'] ?? 999));

        $channels = Channel::where('user_id', $userId)
            ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
            ->orderBy('channel')
            ->get();

        $renumbered = 0;

        foreach ($channels as $channel) {
            $current = (int) floor((float) ($channel->channel ?? 0));
            $isOutOfRange = $current < $min || $current > $max;

            if (! $isOutOfRange) {
                continue;
            }

            $replacement = $this->findNextFreeChannelNumber($userId, $min, $max, (int) $channel->id);
            if ($replacement === null) {
                continue;
            }

            if ((int) $channel->channel === $replacement) {
                continue;
            }

            $channel->channel = $replacement;
            $channel->sort = (float) $replacement;
            $channel->save();
            $renumbered++;
        }

        if ($renumbered > 0) {
            $context->info("Healed Xtream compatibility by renumbering {$renumbered} channel(s) to {$min}-{$max}.");
        }
    }

    private function healXtreamTextCompatibility(array $settings, int $userId, PluginExecutionContext $context): void
    {
        if (! ((bool) ($settings['xtream_ascii_text_mode'] ?? true))) {
            return;
        }

        $channels = Channel::where('user_id', $userId)
            ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
            ->get();

        $updated = 0;

        foreach ($channels as $channel) {
            $changed = false;
            $channelInfo = is_array($channel->info) ? $channel->info : [];
            $fallbackLogin = trim((string) ($channelInfo['twitch_login'] ?? ''));
            $fallbackDisplayName = trim((string) ($channelInfo['twitch_display_name'] ?? ''));
            $fallbackName = $fallbackDisplayName !== '' ? $fallbackDisplayName : $fallbackLogin;
            if ($fallbackName === '') {
                $fallbackName = 'streamarr-'.$channel->id;
            }

            $fallbackTitle = trim((string) ($channelInfo['twitch_title'] ?? ''));
            if ($fallbackTitle === '') {
                $fallbackTitle = $fallbackName;
            }

            $safeTitle = $this->sanitizeXtreamText((string) ($channel->title ?? ''), $settings);
            if ($safeTitle === '') {
                $safeTitle = $this->sanitizeXtreamText($fallbackTitle, $settings);
            }
            if ($safeTitle === '') {
                $safeTitle = $fallbackTitle;
            }
            if ($safeTitle !== '' && $safeTitle !== $channel->title) {
                $channel->title = $safeTitle;
                $changed = true;
            }

            $safeName = $this->sanitizeXtreamText((string) ($channel->name ?? ''), $settings);
            if ($safeName === '') {
                $safeName = $this->sanitizeXtreamText($fallbackName, $settings);
            }
            if ($safeName === '') {
                $safeName = $fallbackName;
            }
            if ($safeName !== '' && $safeName !== $channel->name) {
                $channel->name = $safeName;
                $changed = true;
            }

            if ($changed) {
                $channel->save();
                $updated++;
            }
        }

        if ($updated > 0) {
            $context->info("Healed Xtream text compatibility for {$updated} channel(s).");
        }
    }

    private function sanitizeXtreamText(string $value, array $settings): string
    {
        if (! ((bool) ($settings['xtream_ascii_text_mode'] ?? true))) {
            return trim($value);
        }

        $normalized = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? '');

        $ascii = Str::ascii($normalized);
        $ascii = trim(preg_replace('/\s+/', ' ', $ascii) ?? '');

        return $ascii !== '' ? $ascii : $normalized;
    }

    private function resolveOrCreateGroup(string $groupName, int $userId, ?int $playlistId, ?int $customPlaylistId): ?Group
    {
        $normalizedGroupName = trim($groupName);
        if ($normalizedGroupName === '') {
            return null;
        }

        if ($playlistId) {
            $group = Group::firstOrCreate(
                ['name' => $normalizedGroupName, 'user_id' => $userId, 'playlist_id' => $playlistId],
                ['user_id' => $userId, 'playlist_id' => $playlistId],
            );

            $this->ensureGroupVisibleInClients($group);

            return $group;
        }

        // custom_playlist mode does not use Groups; tagging happens on the channel.
        return null;
    }

    /**
     * Resolve a CustomPlaylist + Tag pair for the given group name when the user
     * targets a custom playlist instead of a regular playlist. Returns
     * [CustomPlaylist|null, Tag|null] for downstream sync/attach.
     *
     * @return array{0: ?CustomPlaylist, 1: ?Tag}
     */
    private function resolveCustomPlaylistGroupTag(?int $customPlaylistId, string $groupName): array
    {
        if (! $customPlaylistId) {
            return [null, null];
        }

        $customPlaylist = CustomPlaylist::find($customPlaylistId);
        if (! $customPlaylist || empty($customPlaylist->uuid)) {
            return [$customPlaylist, null];
        }

        $groupTag = $customPlaylist->groupTags()->where('name->en', $groupName)->first();
        if (! $groupTag) {
            $groupTag = Tag::create(['name' => ['en' => $groupName], 'type' => $customPlaylist->uuid]);
            $customPlaylist->attachTag($groupTag);
        }

        return [$customPlaylist, $groupTag];
    }

    private function ensureGroupVisibleInClients(Group $group): void
    {
        $needsSave = false;

        if ($this->groupsEnabledColumnExists() && ! (bool) $group->enabled) {
            $group->enabled = true;
            $needsSave = true;
        }

        if ($this->groupsSortOrderColumnExists()) {
            $sortOrder = (float) ($group->sort_order ?? 9999);
            if ($sortOrder >= 9999) {
                $group->sort_order = 1;
                $needsSave = true;
            }
        }

        if ($needsSave) {
            $group->save();
        }
    }

    private function groupsEnabledColumnExists(): bool
    {
        if ($this->groupsEnabledColumnExists !== null) {
            return $this->groupsEnabledColumnExists;
        }

        $this->groupsEnabledColumnExists = Schema::hasColumn('groups', 'enabled');

        return $this->groupsEnabledColumnExists;
    }

    private function groupsSortOrderColumnExists(): bool
    {
        if ($this->groupsSortOrderColumnExists !== null) {
            return $this->groupsSortOrderColumnExists;
        }

        $this->groupsSortOrderColumnExists = Schema::hasColumn('groups', 'sort_order');

        return $this->groupsSortOrderColumnExists;
    }

    /**
     * Extract a short hostname label for log lines, e.g. "dlive.tv" from
     * "https://dlive.tv/foo". Strips a leading "www." for readability.
     * Returns null when the URL is unparseable.
     */
    private function extractHostLabel(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    /**
     * Detect URL platform bucket. Returns one of:
     *   'twitch' | 'youtube' | 'kick' | 'generic'
     * Bare logins (no slash, no whitespace, no scheme) are treated as twitch.
     */
    private function detectPlatformBucket(string $line): string
    {
        // Bare "@Handle" shortcut for YouTube. Kept BEFORE URL checks so a
        // stray "@" never reaches the bare-token Twitch fallback.
        if (preg_match('/^@[A-Za-z0-9._-]{1,}$/', $line)) {
            return 'youtube';
        }
        // "kick:slug" shortcut for Kick. Required because a bare slug would
        // collide with Twitch bare logins.
        if (preg_match('/^kick:[A-Za-z0-9._-]{1,}$/i', $line)) {
            return 'kick';
        }
        if ($this->isYouTubeUrl($line)) {
            return 'youtube';
        }
        if ($this->isTwitchUrl($line)) {
            return 'twitch';
        }
        if (preg_match('#^https?://(www\.)?kick\.com/#i', $line)) {
            return 'kick';
        }
        if (preg_match('#^https?://#i', $line)) {
            return 'generic';
        }
        // Bare token (no scheme, no slash, no spaces) is a Twitch login.
        if (! str_contains($line, '/') && ! preg_match('/\s/', $line)) {
            return 'twitch';
        }

        return 'generic';
    }

    /**
     * Auto-migrate the legacy `monitored_channels` textarea into the
     * per-platform fields. Idempotent: a marker `__streamarr_legacy_migrated`
     * prevents repeated work. Returns true if a migration was performed.
     *
     * Mutates the provided $settings array even when DB persistence fails so
     * the same execution can use the migrated values immediately.
     */
    private function migrateLegacyMonitoredChannels(array &$settings, PluginExecutionContext $ctx, $plugin): bool
    {
        if (($settings['__streamarr_legacy_migrated'] ?? false) === true) {
            return false;
        }

        $raw = $settings['monitored_channels'] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            // Nothing to migrate, but still mark so we never re-check.
            $settings['__streamarr_legacy_migrated'] = true;
            $this->persistSettings($plugin, $settings, $ctx);

            return false;
        }

        $buckets = [
            'twitch' => $this->splitLines((string) ($settings['twitch_channels'] ?? '')),
            'youtube' => $this->splitLines((string) ($settings['youtube_channels'] ?? '')),
            'kick' => $this->splitLines((string) ($settings['kick_channels'] ?? '')),
            'generic' => $this->splitLines((string) ($settings['generic_channels'] ?? '')),
        ];

        $existsLower = [];
        foreach ($buckets as $key => $entries) {
            $existsLower[$key] = array_change_key_case(array_flip(array_map('strtolower', $entries)), CASE_LOWER);
        }

        $migrated = 0;
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $bucket = $this->detectPlatformBucket($line);
            $key = strtolower($line);
            if (isset($existsLower[$bucket][$key])) {
                continue;
            }

            $buckets[$bucket][] = $line;
            $existsLower[$bucket][$key] = true;
            $migrated++;
        }

        $settings['twitch_channels'] = implode("\n", $buckets['twitch']);
        $settings['youtube_channels'] = implode("\n", $buckets['youtube']);
        $settings['kick_channels'] = implode("\n", $buckets['kick']);
        $settings['generic_channels'] = implode("\n", $buckets['generic']);
        $settings['__streamarr_legacy_migrated'] = true;

        $ctx->info("legacy migration: {$migrated} entries split into per-platform fields (twitch=".count($buckets['twitch']).", youtube=".count($buckets['youtube']).", kick=".count($buckets['kick']).", generic=".count($buckets['generic']).')');

        $this->persistSettings($plugin, $settings, $ctx);

        return true;
    }

    /**
     * Best-effort persistence of the migrated settings to the plugin model.
     * Logs a warning if the model is missing or the write fails so the in-
     * memory copy can still be used for the current run.
     */
    private function persistSettings($plugin, array $settings, PluginExecutionContext $ctx): void
    {
        if ($plugin === null || ! is_object($plugin) || ! method_exists($plugin, 'update')) {
            $ctx->warning('legacy migration: could not persist settings (no plugin model on context).');

            return;
        }

        try {
            $plugin->update(['settings' => $settings]);
        } catch (\Throwable $e) {
            $ctx->warning('legacy migration: could not persist settings: '.$e->getMessage());
        }
    }

    /**
     * Split a textarea value into a list of trimmed non-empty, non-comment lines.
     *
     * @return list<string>
     */
    private function splitLines(string $raw): array
    {
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * Build a ProviderRegistry seeded with all currently supported platform
     * stubs. Specific providers come first; the catch-all GenericProvider
     * MUST be registered last so more specific matchers win.
     *
     * Phase 1 only uses the registry for the non-Twitch streamlink loop in
     * handleCheckNow. Detection bodies on the stubs are intentionally empty;
     * the orchestrator still drives streamlink directly.
     */
    private function buildRegistry(array $settings): Providers\ProviderRegistry
    {
        $registry = new Providers\ProviderRegistry();
        $registry->register(new Providers\TwitchProvider());
        $registry->register(new Providers\YouTubeProvider());
        $registry->register(new Providers\KickProvider());
        $registry->register(new Providers\GenericProvider());

        return $registry;
    }

    /**
     * Central per-platform channel resolver.
     *
     * Twitch returns structured entries [['login','base_number'], ...].
     * YouTube / Kick / Generic return a list of URLs (strings).
     * Lines that don't match the expected platform are dropped with a warning.
     *
     * @return list<array{login: string, base_number: int|null}>|list<string>
     */
    private function getChannelsForPlatform(string $platformId, array $settings, ?PluginExecutionContext $ctx = null): array
    {
        $platformId = strtolower($platformId);
        $key = $platformId.'_channels';
        $raw = (string) ($settings[$key] ?? '');
        if (trim($raw) === '') {
            return [];
        }

        if ($platformId === 'twitch') {
            return $this->parseMonitoredChannels($raw);
        }

        $out = [];
        foreach ($this->splitLines($raw) as $line) {
            $bucket = $this->detectPlatformBucket($line);
            if ($bucket !== $platformId) {
                if ($ctx) {
                    $ctx->warning("Ignoring entry in {$key}: '{$line}' does not match platform '{$platformId}'.");
                }
                continue;
            }

            if ($platformId === 'youtube') {
                // Expand bare "@Handle" shortcut to a canonical channel URL so
                // the downstream pipeline (normalizeYouTubeUrl, streamlink,
                // YouTube Data API handle resolution) keeps working unchanged.
                if (preg_match('/^@[A-Za-z0-9._-]{1,}$/', $line)) {
                    $line = 'https://www.youtube.com/'.$line;
                }
                $out[] = $this->normalizeYouTubeUrl($line);
            } elseif ($platformId === 'kick') {
                // Expand "kick:slug" shortcut to a canonical channel URL.
                if (preg_match('/^kick:([A-Za-z0-9._-]{1,})$/i', $line, $m)) {
                    $line = 'https://kick.com/'.$m[1];
                }
                $out[] = $line;
            } else {
                $out[] = $line;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Resolve the group name for a platform via the chain:
     *   {platform}_group -> channel_group -> provider default -> 'Live Streams'
     */
    private function resolveGroupForPlatform(string $platformId, array $settings, ?string $contextHint = null): string
    {
        $platformId = strtolower($platformId);

        $perPlatform = trim((string) ($settings[$platformId.'_group'] ?? ''));
        if ($perPlatform !== '') {
            return $perPlatform;
        }

        $generic = trim((string) ($settings['channel_group'] ?? ''));
        if ($generic !== '') {
            return $generic;
        }

        $defaults = [
            'twitch' => 'Twitch Live',
            'youtube' => 'YouTube Live',
            'kick' => 'Kick Live',
            'generic' => 'Live Streams',
        ];

        return $defaults[$platformId] ?? 'Live Streams';
    }

    /**
     * Merge the new per-platform channel fields and the legacy
     * monitored_channels textarea into one newline-separated string so the
     * existing Twitch / YouTube / Generic parsers can keep working unchanged.
     *
     * @deprecated since v1.11.0. handleCheckNow() now uses
     *             getChannelsForPlatform() per platform. Kept for backward
     *             compatibility with any out-of-tree callers.
     */
    private function buildCombinedChannelSource(array $settings): string
    {
        $parts = [];
        foreach (['twitch_channels', 'youtube_channels', 'kick_channels', 'generic_channels', 'monitored_channels'] as $key) {
            $value = trim((string) ($settings[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Parse the monitored_channels textarea into structured Twitch entries.
     *
     * Supports:
     *   username
     *   username=BaseNumber
     *
     * YouTube URLs are skipped here; use parseMonitoredYouTubeUrls() for those.
     *
     * @return list<array{login: string, base_number: int|null}>
     */
    private function parseMonitoredChannels(string $raw): array
    {
        $entries = [];

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Skip YouTube URLs. handled separately
            if ($this->isYouTubeUrl($line)) {
                continue;
            }

            // Skip any other http(s):// URL. handled by the generic streamlink path.
            // Twitch website URLs (https://www.twitch.tv/xqc) currently fall through
            // here too; users should use the bare login form instead.
            if (preg_match('#^https?://#i', $line)) {
                continue;
            }

            $login = null;
            $baseNumber = null;

            if (preg_match('/^([\w.-]+)(?:=(\d+))?$/', $line, $m)) {
                $login = strtolower($m[1]);
                $baseNumber = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
            } else {
                // Last-resort: if the line still looks like a plain word, treat it as
                // a Twitch login. Anything that clearly isn't (slashes, spaces, etc.)
                // is silently dropped. better than sending garbage to Helix.
                $clean = strtolower(trim($line));
                if ($clean !== '' && preg_match('/^[\w.-]+$/', $clean)) {
                    $login = $clean;
                }
            }

            if ($login) {
                $entries[] = [
                    'login' => $login,
                    'base_number' => $baseNumber,
                ];
            }
        }

        return $entries;
    }

    /**
     * Parse the monitored_channels textarea and return only YouTube URLs.
     *
     * @deprecated since v1.11.0. Use getChannelsForPlatform('youtube', ...).
     *
     * @return list<string>
     */
    private function parseMonitoredYouTubeUrls(string $raw): array
    {
        $urls = [];

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if ($this->isYouTubeUrl($line)) {
                $urls[] = $this->normalizeYouTubeUrl($line);
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Detect whether a line is a YouTube URL.
     */
    private function isYouTubeUrl(string $entry): bool
    {
        return (bool) preg_match('#^https?://(www\.)?(youtube\.com|youtu\.be)/#i', $entry);
    }

    /**
     * Detect whether a URL is for Twitch (handled by the dedicated Twitch path).
     */
    private function isTwitchUrl(string $entry): bool
    {
        return (bool) preg_match('#^https?://(www\.)?twitch\.tv/#i', $entry);
    }

    /**
     * Parse the monitored_channels textarea and return URLs that should be
     * handled by the generic streamlink provider (anything that isn't Twitch
     * or YouTube. Kick, Vimeo, BiliBili, Rumble, NicoNico, SOOP, DLive,
     * AfreecaTV, Mildom, etc.).
     *
     * @deprecated since v1.11.0. Use getChannelsForPlatform('generic', ...).
     *
     * @return list<string>
     */
    private function parseMonitoredGenericUrls(string $raw): array
    {
        $urls = [];

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! preg_match('#^https?://#i', $line)) {
                continue;
            }

            if ($this->isYouTubeUrl($line) || $this->isTwitchUrl($line)) {
                continue;
            }

            $urls[] = $line;
        }

        return array_values(array_unique($urls));
    }

    /**
     * Normalize a YouTube channel URL so that it points to the live stream page.
     *
     * - @handle URLs  → https://www.youtube.com/@handle/live
     * - /channel/ID   → https://www.youtube.com/channel/ID/live
     * - /c/slug       → https://www.youtube.com/c/slug/live
     * - URLs already ending in /live → returned as-is
     * - youtu.be/xxx  → returned as-is (video/live ID. cannot append /live)
     * - watch?v=      → returned as-is
     */
    private function normalizeYouTubeUrl(string $url): string
    {
        $url = trim($url);

        // Already has /live or is a direct video URL
        if (str_contains($url, '/live') || str_contains($url, 'watch?v=') || str_contains($url, 'youtu.be/')) {
            return $url;
        }

        // Strip trailing slash before appending
        return rtrim($url, '/').'/live';
    }

    /**
     * Parse a Twitch URL into type (channel or vod) and value.
     *
     * @return array{type: 'channel'|'vod', value: string}|null
     */
    private function parseTwitchUrl(string $url): ?array
    {
        $url = trim($url);

        // VOD URL: twitch.tv/videos/123456789
        if (preg_match('/twitch\.tv\/videos\/(\d+)/', $url, $m)) {
            return ['type' => 'vod', 'value' => $m[1]];
        }

        // Channel URL: twitch.tv/username
        if (preg_match('/twitch\.tv\/([\w.-]+)/', $url, $m)) {
            $login = strtolower($m[1]);
            // Filter out non-channel paths
            if (in_array($login, ['directory', 'videos', 'settings', 'subscriptions', 'inventory', 'wallet'], true)) {
                return null;
            }

            return ['type' => 'channel', 'value' => $login];
        }

        // Bare username (no URL)
        if (preg_match('/^[\w.-]+$/', $url)) {
            return ['type' => 'channel', 'value' => strtolower($url)];
        }

        return null;
    }

    /**
     * @return array{userId: int|null, profile: StreamProfile|null}
     */
    private function resolveContext(PluginExecutionContext $context): array
    {
        $profile = $this->loadStreamProfile($context->settings);
        $userId = $context->user?->id;

        if (! $userId) {
            $profileUserId = (int) ($profile?->user_id ?? 0);
            $userId = $profileUserId > 0 ? $profileUserId : null;
        }

        return ['userId' => $userId, 'profile' => $profile];
    }

    private function loadStreamProfile(array $settings): ?StreamProfile
    {
        $profileId = (int) ($settings['stream_profile_id'] ?? 0);

        return $profileId ? StreamProfile::find($profileId) : null;
    }

    private function getCookiesFile(?StreamProfile $profile): ?string
    {
        if (! $profile || empty($profile->cookies)) {
            return null;
        }

        $content = trim($profile->cookies);
        if ($content === '') {
            return null;
        }

        try {
            $path = tempnam(sys_get_temp_dir(), 'streamarr_cookies_').'.txt';
            file_put_contents($path, $content."\n");

            return $path;
        } catch (\Throwable) {
            return null;
        }
    }

    private function cleanupCookiesFile(?string $path): void
    {
        if ($path && file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * @param  list<string>  $cmd
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runProcess(array $cmd, int $timeoutSeconds = 30): array
    {
        $result = Process::timeout($timeoutSeconds)->run($cmd);

        return [
            'exit' => $result->exitCode() ?? -1,
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
        ];
    }

    // -------------------------------------------------------------------------
    // EPG (Electronic Programme Guide)
    // -------------------------------------------------------------------------

    /**
     * Ensure a "Streamarr" EPG source exists for the given user.
     * Caches the result for the duration of the current action run.
     */
    private function ensureEpgSource(int $userId): Epg
    {
        if ($this->epgSource) {
            return $this->epgSource;
        }

        $this->epgSource = Epg::firstOrCreate(
            ['name' => 'Streamarr', 'user_id' => $userId, 'source_type' => EpgSourceType::URL],
            [
                'url' => null,
                'auto_sync' => false,
                'synced' => now(),
                'status' => Status::Completed,
                'processing' => false,
                'processing_phase' => null,
                'is_cached' => true,
                'cache_progress' => 100,
            ],
        );

        // Heal existing records: fix stuck sync state, ensure cache-only mode
        if (! $this->epgSource->synced || $this->epgSource->auto_sync || $this->epgSource->processing) {
            $this->epgSource->update([
                'auto_sync' => false,
                'synced' => now(),
                'status' => Status::Completed,
                'processing' => false,
                'processing_phase' => null,
                'is_cached' => true,
                'cache_progress' => 100,
            ]);
        }

        return $this->epgSource;
    }

    /**
     * Ensure an EPG channel exists for the given Twitch login.
     *
     * @param  array{display_name?: string, logo?: string, language?: string}  $metadata
     */
    private function ensureEpgChannel(Epg $epg, int $userId, string $login, array $metadata): EpgChannel
    {
        $channelId = 'streamarr-'.$login;

        $epgChannel = EpgChannel::firstOrCreate(
            ['name' => $channelId, 'channel_id' => $channelId, 'epg_id' => $epg->id, 'user_id' => $userId],
            [
                'display_name' => $metadata['display_name'] ?? $login,
                'icon' => $metadata['logo'] ?? '',
                'lang' => $metadata['language'] ?? '',
            ],
        );

        // Keep display name and icon up to date
        $epgChannel->updateQuietly([
            'display_name' => $metadata['display_name'] ?? $login,
            'icon' => $metadata['logo'] ?? '',
        ]);

        return $epgChannel;
    }

    /**
     * Write EPG programme data for all live Streamarr channels.
     * Splits programmes across date-based JSONL files (today + 3 days).
     */
    private function writeEpgData(int $userId): void
    {
        $epg = $this->epgSource ?? $this->ensureEpgSource($userId);

        /** @var Collection<int, Channel> $channels */
        $channels = Channel::where('user_id', $userId)
            ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
            ->whereJsonContains('info->twitch_stream_type', self::STREAM_TYPE_LIVE)
            ->get();

        $cacheDir = "epg-cache/{$epg->uuid}/v1";
        $now = Carbon::now();
        $daysForward = 3;

        // Collect date range for file output
        $minDate = $now->copy()->startOfDay();
        $maxDate = $now->copy()->addDays($daysForward)->endOfDay();

        // Group programmes by date
        /** @var array<string, list<string>> $programmesByDate */
        $programmesByDate = [];
        $channelsData = [];
        $totalProgrammes = 0;

        foreach ($channels as $channel) {
            $info = $channel->info ?? [];
            $login = $info['twitch_login'] ?? '';

            if (! $login) {
                continue;
            }

            $channelId = 'streamarr-'.$login;

            $channelsData[$channelId] = [
                'id' => $channelId,
                'display_name' => $info['twitch_display_name'] ?? $login,
                'icon' => $channel->logo_internal ?? '',
                'lang' => $channel->lang ?? '',
            ];

            $history = $info['twitch_epg_history'] ?? [];
            $currentGame = $info['twitch_game'] ?? '';
            $currentGameArt = $info['twitch_game_box_art'] ?? '';

            if ($this->epgMode === 'title') {
                // Title mode: each title change becomes a separate programme entry
                $titleHistory = $info['twitch_title_history'] ?? [];

                if (empty($titleHistory)) {
                    $titleHistory = [[
                        'title' => trim((string) ($info['twitch_title'] ?? $channel->title ?? '')),
                        'started_at' => $info['twitch_stream_started'] ?? $now->toISOString(),
                    ]];
                }

                foreach ($titleHistory as $i => $segment) {
                    $start = Carbon::parse($segment['started_at']);
                    $isLastSegment = ! isset($titleHistory[$i + 1]);

                    $stop = $isLastSegment
                        ? $maxDate->copy()
                        : Carbon::parse($titleHistory[$i + 1]['started_at']);

                    if ($stop->lt($minDate) || $start->gt($maxDate)) {
                        continue;
                    }

                    if ($start->lt($minDate)) {
                        $start = $minDate->copy();
                    }
                    if ($stop->gt($maxDate)) {
                        $stop = $maxDate->copy();
                    }

                    $segmentTitle = trim((string) ($segment['title'] ?? ''));

                    $programme = [
                        'channel' => $channelId,
                        'start' => $start->toISOString(),
                        'stop' => $stop->toISOString(),
                        'title' => $segmentTitle !== ''
                            ? $segmentTitle
                            : ($channel->title ?: ($info['twitch_display_name'] ?? $login)),
                        'subtitle' => $info['twitch_display_name'] ?? $login,
                        'desc' => $currentGame ? "Playing: {$currentGame}" : '',
                        'category' => 'Live Stream',
                        'episode_num' => '',
                        'rating' => '',
                        'icon' => $currentGameArt ?: ($channel->logo_internal ?? ''),
                        'images' => [],
                        'new' => false,
                    ];

                    $line = json_encode(['channel' => $channelId, 'programme' => $programme], JSON_UNESCAPED_UNICODE);

                    $datePointer = $start->copy()->startOfDay();
                    while ($datePointer->lte($stop)) {
                        $dateKey = $datePointer->format('Y-m-d');
                        $programmesByDate[$dateKey][] = $line;
                        $datePointer->addDay();
                    }

                    $totalProgrammes++;
                }

                continue;
            }

            // Game mode: each game change becomes a separate programme entry
            if (empty($history)) {
                // No history yet. create a single programme spanning to maxDate
                $history = [[
                    'game' => $currentGame,
                    'game_box_art' => $currentGameArt,
                    'started_at' => $now->toISOString(),
                ]];
            }

            foreach ($history as $i => $segment) {
                $start = Carbon::parse($segment['started_at']);
                $isLastSegment = ! isset($history[$i + 1]);

                // Last segment: extend to fill the EPG guide window
                $stop = $isLastSegment
                    ? $maxDate->copy()
                    : Carbon::parse($history[$i + 1]['started_at']);

                // Skip segments entirely outside our date window
                if ($stop->lt($minDate) || $start->gt($maxDate)) {
                    continue;
                }

                // Clamp to our date window
                if ($start->lt($minDate)) {
                    $start = $minDate->copy();
                }
                if ($stop->gt($maxDate)) {
                    $stop = $maxDate->copy();
                }

                $programme = [
                    'channel' => $channelId,
                    'start' => $start->toISOString(),
                    'stop' => $stop->toISOString(),
                    'title' => $segment['game'] ?: 'Live',
                    'subtitle' => $info['twitch_display_name'] ?? $login,
                    'desc' => $channel->title ?? '',
                    'category' => 'Live Stream',
                    'episode_num' => '',
                    'rating' => '',
                    'icon' => $segment['game_box_art'] ?? '',
                    'images' => [],
                    'new' => false,
                ];

                $line = json_encode(['channel' => $channelId, 'programme' => $programme], JSON_UNESCAPED_UNICODE);

                // Add to every date file that this programme spans
                $datePointer = $start->copy()->startOfDay();
                while ($datePointer->lte($stop)) {
                    $dateKey = $datePointer->format('Y-m-d');
                    $programmesByDate[$dateKey][] = $line;
                    $datePointer->addDay();
                }

                $totalProgrammes++;
            }
        }

        // Write date-based JSONL files
        for ($d = 0; $d <= $daysForward; $d++) {
            $dateKey = $now->copy()->addDays($d)->format('Y-m-d');
            $lines = $programmesByDate[$dateKey] ?? [];
            Storage::disk('local')->put(
                "{$cacheDir}/programmes-{$dateKey}.jsonl",
                empty($lines) ? '' : implode("\n", $lines),
            );
        }

        // Write channels.json
        Storage::disk('local')->put(
            "{$cacheDir}/channels.json",
            json_encode($channelsData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );

        // Write metadata
        $metadata = [
            'cache_created' => time(),
            'cache_version' => 'v1',
            'epg_uuid' => $epg->uuid,
            'total_channels' => count($channelsData),
            'total_programmes' => $totalProgrammes,
            'programme_date_range' => [
                'min_date' => $minDate->format('Y-m-d'),
                'max_date' => $maxDate->format('Y-m-d'),
            ],
        ];

        Storage::disk('local')->put(
            "{$cacheDir}/metadata.json",
            json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );

        $epg->update([
            'is_cached' => true,
            'cache_progress' => 100,
            'cache_meta' => $metadata,
            'channel_count' => count($channelsData),
            'programme_count' => $totalProgrammes,
        ]);
    }
}
