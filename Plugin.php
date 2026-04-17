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

    // -------------------------------------------------------------------------
    // PluginInterface
    // -------------------------------------------------------------------------

    public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return match ($action) {
            'check_now' => $this->handleCheckNow($context),
            'add_manual' => $this->handleAddManual($payload, $context),
            'cleanup' => $this->handleCleanup($context),
            'reset_all' => $this->handleResetAll($context),
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

        $this->epgMode = $settings['epg_mode'] ?? 'game';
        $channelEntries = $this->parseMonitoredChannels($settings['monitored_channels'] ?? '');

        if (empty($channelEntries)) {
            return PluginActionResult::failure('No channels configured. Add Twitch usernames to Monitored Channels.');
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

        $context->heartbeat('Detecting live streams…', progress: 5);

        // --- Build lookup map: login → entry (for base_number) ---
        $entryMap = [];
        foreach ($channelEntries as $entry) {
            $entryMap[strtolower($entry['login'])] = $entry;
        }

        // --- Detect live streams ---
        $liveStreams = [];
        $userProfiles = [];

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
        $this->ensureEpgSource($userId);

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
                    $logo = $stream['profile_image'] ?: ($userProfiles[$login]['profile_image'] ?? '');
                    $epgChannel = $this->ensureEpgChannel($this->epgSource, $userId, $login, [
                        'display_name' => $stream['display_name'] ?? $login,
                        'logo' => $logo,
                        'language' => $stream['language'] ?? '',
                    ]);
                    $existing->update(['epg_channel_id' => $epgChannel->id]);
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

            $metadata = [
                'login' => $login,
                'display_name' => $stream['display_name'] ?? $login,
                'user_id' => $stream['user_id'] ?? ($userProfiles[$login]['user_id'] ?? ''),
                'title' => $stream['title'] ?? "{$login} - Live",
                'game' => $stream['game'] ?? '',
                'game_box_art' => $stream['game_box_art'] ?? '',
                'logo' => $logo,
                'thumbnail' => $stream['thumbnail'] ?? '',
                'language' => $stream['language'] ?? '',
            ];

            // Create EPG channel for programme guide
            if ($this->epgSource) {
                $epgChannel = $this->ensureEpgChannel($this->epgSource, $userId, $login, $metadata);
                $metadata['epg_channel_id'] = $epgChannel->id;
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

        // --- Cleanup ---
        if ($settings['auto_cleanup'] ?? true) {
            $context->heartbeat('Cleaning up ended streams…', progress: 90);
            $cleaned = $this->cleanupEndedStreams($settings, $userId, $cookiesFile, $context);
        }

        $this->cleanupCookiesFile($cookiesFile);
        $this->accessToken = null;

        // Write EPG programme data for all live channels
        if ($this->epgSource) {
            $this->writeEpgData($userId);
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

    private function handleAddManual(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $settings = $context->settings;
        $this->epgMode = $settings['epg_mode'] ?? 'game';
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
                $metadata = [
                    'login' => $login,
                    'display_name' => $streamInfo['display_name'] ?? $login,
                    'user_id' => $streamInfo['user_id'] ?? ($userProfile['user_id'] ?? ''),
                    'title' => $streamInfo['title'] ?? "{$login} - Live",
                    'game' => $streamInfo['game'] ?? '',
                    'game_box_art' => $streamInfo['game_box_art'] ?? '',
                    'logo' => $streamInfo['profile_image'] ?? ($userProfile['profile_image'] ?? ''),
                    'thumbnail' => $streamInfo['thumbnail'] ?? '',
                    'language' => $streamInfo['language'] ?? '',
                ];

                // Create EPG channel for programme guide
                $epg = $this->ensureEpgSource($userId);
                $epgChannel = $this->ensureEpgChannel($epg, $userId, $login, $metadata);
                $metadata['epg_channel_id'] = $epgChannel->id;

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
            $this->writeEpgData($userId);
            $this->epgSource = null;
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

    private function handleCleanup(PluginExecutionContext $context): PluginActionResult
    {
        $settings = $context->settings;
        $this->epgMode = $settings['epg_mode'] ?? 'game';
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
        $this->writeEpgData($userId);
        $this->epgSource = null;

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

    // -------------------------------------------------------------------------
    // Channel lifecycle
    // -------------------------------------------------------------------------

    /**
     * @param  array{login: string, display_name: string, user_id: string, title: string, game: string, game_box_art: string, logo: string, thumbnail: string, language?: string, vod_id?: string, vod_data?: array}  $metadata
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

        $group = $playlistId
            ? Group::firstOrCreate(
                ['name' => $groupName, 'user_id' => $userId, 'playlist_id' => $playlistId],
                ['user_id' => $userId, 'playlist_id' => $playlistId],
            )
            : null;

        $customPlaylist = $customPlaylistId ? CustomPlaylist::find($customPlaylistId) : null;

        $groupTag = null;
        if ($customPlaylist) {
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
            $info['twitch_epg_history'] = [[
                'game' => $metadata['game'] ?? '',
                'game_box_art' => $metadata['game_box_art'] ?? '',
                'started_at' => Carbon::now()->toISOString(),
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
                'name' => $metadata['title'],
                'title' => $metadata['title'],
                'year' => $year ?? '',
                'added' => $addedTimestamp ?: (string) time(),
                'category_id' => (string) ($group?->id ?? ''),
                'category_ids' => $group ? [$group->id] : [],
                'container_extension' => 'ts',
                'custom_sid' => '',
                'direct_source' => '',
            ];
        }

        $channel = Channel::create([
            'uuid' => Str::orderedUuid()->toString(),
            'name' => $metadata['display_name'] ?? $login,
            'title' => $metadata['title'],
            'url' => $url,
            'channel' => (int) $channelNumber,
            'sort' => (float) $channelNumber,
            'stream_id' => 'streamarr-'.$login,
            'epg_channel_id' => $metadata['epg_channel_id'] ?? null,
            'lang' => $metadata['language'] ?? '',
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
            'custom_playlist_id' => $playlistId ? null : $customPlaylist?->id,
            'info' => $info,
            'container_extension' => $isVod ? 'ts' : null,
            'year' => $year,
            'movie_data' => $movieData,
        ]);

        // Update movie_data.stream_id with actual channel ID
        if ($isVod && $movieData) {
            $movieData['stream_id'] = $channel->id;
            $channel->update(['movie_data' => $movieData]);
        }

        if ($customPlaylist) {
            $channel->customPlaylists()->syncWithoutDetaching([$customPlaylist->id]);
            if ($groupTag) {
                $channel->attachTag($groupTag);
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

        $newTitle = $stream['title'] ?? '';
        if ($newTitle && $newTitle !== $channel->title) {
            $channel->title = $newTitle;
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

        // Re-group if game mode and game changed
        $groupMode = $settings['group_mode'] ?? 'static';
        if ($groupMode === 'game' && $newGame !== $oldGame && $newGame !== '') {
            $playlistId = (int) ($settings['target_playlist_id'] ?? 0) ?: null;
            $customPlaylistId = (int) ($settings['target_custom_playlist_id'] ?? 0) ?: null;

            if ($playlistId) {
                $newGroup = Group::firstOrCreate(
                    ['name' => $newGame, 'user_id' => $userId, 'playlist_id' => $playlistId],
                    ['user_id' => $userId, 'playlist_id' => $playlistId],
                );
                $channel->group_id = $newGroup->id;
                $changed = true;
            }

            if ($customPlaylistId) {
                $customPlaylist = CustomPlaylist::find($customPlaylistId);
                if ($customPlaylist) {
                    // Remove old game tag, add new one
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
                    $changed = true;
                }
            }
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
        /** @var Collection<int, Channel> $channels */
        $channels = Channel::where('user_id', $userId)
            ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
            ->whereJsonContains('info->twitch_stream_type', self::STREAM_TYPE_LIVE)
            ->get();

        if ($channels->isEmpty()) {
            return 0;
        }

        $useApi = $this->hasTwitchApiCredentials($settings) && $this->accessToken;
        $cleaned = 0;

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
            $streamlink = $this->findStreamlink();
            if (! $streamlink) {
                $context->warning('Cannot check stream status - streamlink not found.');

                return 0;
            }

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

        return $cleaned;
    }

    // -------------------------------------------------------------------------
    // Channel numbering
    // -------------------------------------------------------------------------

    private function nextChannelNumber(int $userId, array $settings, string $login, ?int $baseNumber): int|float
    {
        $mode = $settings['channel_numbering_mode'] ?? 'sequential';
        $increment = (int) ($settings['channel_number_increment'] ?? 1);
        $starting = (int) ($settings['starting_channel_number'] ?? 3000);

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
            return $starting;
        }

        return (int) $last + $increment;
    }

    // -------------------------------------------------------------------------
    // Twitch Helix API
    // -------------------------------------------------------------------------

    private function hasTwitchApiCredentials(array $settings): bool
    {
        return ! empty($settings['twitch_client_id']) && ! empty($settings['twitch_client_secret']);
    }

    /**
     * Obtain an App Access Token via client_credentials grant.
     */
    private function getAppAccessToken(array $settings): ?string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $response = Http::asForm()->post('https://id.twitch.tv/oauth2/token', [
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

            $response = Http::withHeaders([
                'Client-ID' => $settings['twitch_client_id'],
                'Authorization' => "Bearer {$this->accessToken}",
            ])->get("https://api.twitch.tv/helix/users?{$query}");

            if (! $response->successful()) {
                continue;
            }

            foreach ($response->json('data', []) as $user) {
                $login = strtolower($user['login'] ?? '');
                if ($login) {
                    // Downsize to 70x70 — sufficient for channel logos and saves bandwidth
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
     * @return list<array{login: string, display_name: string, user_id: string, title: string, game: string, game_box_art: string, thumbnail: string, profile_image: string, language: string}>
     */
    private function batchGetStreams(array $settings, array $logins): array
    {
        $results = [];

        foreach (array_chunk($logins, 100) as $chunk) {
            $query = collect($chunk)->map(fn ($l) => "user_login={$l}")->implode('&');

            $response = Http::withHeaders([
                'Client-ID' => $settings['twitch_client_id'],
                'Authorization' => "Bearer {$this->accessToken}",
            ])->get("https://api.twitch.tv/helix/streams?{$query}");

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
        $response = Http::withHeaders([
            'Client-ID' => $settings['twitch_client_id'],
            'Authorization' => "Bearer {$this->accessToken}",
        ])->get("https://api.twitch.tv/helix/videos", [
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
        $response = Http::withHeaders([
            'Client-ID' => $settings['twitch_client_id'],
            'Authorization' => "Bearer {$this->accessToken}",
        ])->get('https://api.twitch.tv/helix/videos', [
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
     * @return array{login: string, display_name: string, user_id: string, title: string, game: string, game_box_art: string, thumbnail: string, profile_image: string, language: string}|null
     */
    private function checkChannelLiveViaStreamlink(string $binary, string $login, ?string $cookiesFile): ?array
    {
        $url = "https://www.twitch.tv/{$login}";
        $cmd = [$binary, '--json', $url];

        if ($cookiesFile) {
            $cmd[] = '--twitch-api-header';
            $cmd[] = "Cookie=$(cat {$cookiesFile})";
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

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Parse Twitch ISO 8601 duration (e.g. "3h24m10s") into total seconds.
     */
    private function parseTwitchDuration(string $duration): int
    {
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
                // Downsize to 70x70 — sufficient for channel logos and saves bandwidth
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
            return $settings['vod_group'] ?? 'Twitch VODs';
        }

        $groupMode = $settings['group_mode'] ?? 'static';

        if ($groupMode === 'game' && ! empty($metadata['game'])) {
            return $metadata['game'];
        }

        return $settings['channel_group'] ?? 'Twitch Live';
    }

    /**
     * Parse the monitored_channels textarea into structured entries.
     *
     * Supports:
     *   username
     *   username=BaseNumber
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

            $login = null;
            $baseNumber = null;

            if (preg_match('/^([\w.-]+)(?:=(\d+))?$/', $line, $m)) {
                $login = strtolower($m[1]);
                $baseNumber = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
            } else {
                $login = strtolower(trim($line));
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
        $userId = $context->user?->id ?? $profile?->user_id;

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
                // Title mode: single programme per channel using the stream title
                $streamStarted = $info['twitch_stream_started'] ?? $now->toISOString();
                $start = Carbon::parse($streamStarted);

                if ($start->lt($minDate)) {
                    $start = $minDate->copy();
                }

                $programme = [
                    'channel' => $channelId,
                    'start' => $start->toISOString(),
                    'stop' => $maxDate->copy()->toISOString(),
                    'title' => $channel->title ?: ($info['twitch_display_name'] ?? $login),
                    'subtitle' => $currentGame ?: '',
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
                while ($datePointer->lte($maxDate)) {
                    $dateKey = $datePointer->format('Y-m-d');
                    $programmesByDate[$dateKey][] = $line;
                    $datePointer->addDay();
                }

                $totalProgrammes++;

                continue;
            }

            // Game mode: each game change becomes a separate programme entry
            if (empty($history)) {
                // No history yet — create a single programme spanning to maxDate
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
