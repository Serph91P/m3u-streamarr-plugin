<?php

namespace AppLocalPlugins\Streamarr;

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\StreamProfile;
use App\Plugins\Contracts\ChannelProcessorPluginInterface;
use App\Plugins\Contracts\PluginInterface;
use App\Plugins\Contracts\ScheduledPluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Spatie\Tags\Tag;

class Plugin implements ChannelProcessorPluginInterface, PluginInterface, ScheduledPluginInterface
{
    private const PLUGIN_MARKER = 'streamarr';

    private const STREAM_TYPE_LIVE = 'live';

    private const STREAM_TYPE_VOD = 'vod';

    /** @var string|null Cached Twitch App Access Token for current action run */
    private ?string $accessToken = null;

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

        $context->heartbeat('Processing live streams…', progress: 70);

        // --- Process live streams ---
        foreach ($liveStreams as $stream) {
            $login = strtolower($stream['login']);

            $existing = Channel::where('user_id', $userId)
                ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
                ->whereJsonContains('info->twitch_login', $login)
                ->whereJsonContains('info->twitch_stream_type', self::STREAM_TYPE_LIVE)
                ->first();

            if ($existing) {
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
            ];

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
                ];

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

                $channelNumber = $this->nextChannelNumber($userId, $settings, 'vod', null);
                $metadata = [
                    'login' => 'vod',
                    'display_name' => 'VOD',
                    'user_id' => '',
                    'title' => "Twitch VOD #{$vodId}",
                    'game' => '',
                    'game_box_art' => '',
                    'logo' => '',
                    'thumbnail' => '',
                    'vod_id' => $vodId,
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

        $context->info("Deleted {$count} channel(s) created by Streamarr.");

        return PluginActionResult::success("Reset complete - deleted {$count} channel(s).", ['deleted' => $count]);
    }

    // -------------------------------------------------------------------------
    // Channel lifecycle
    // -------------------------------------------------------------------------

    /**
     * @param  array{login: string, display_name: string, user_id: string, title: string, game: string, game_box_art: string, logo: string, thumbnail: string, vod_id?: string}  $metadata
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

        if ($vodId) {
            $info['twitch_vod_id'] = $vodId;
        }

        if (! empty($metadata['game'])) {
            $info['twitch_game'] = $metadata['game'];
        }

        if (! empty($metadata['game_box_art'])) {
            $info['twitch_game_box_art'] = $metadata['game_box_art'];
        }

        $channel = Channel::create([
            'uuid' => Str::orderedUuid()->toString(),
            'title' => $metadata['title'],
            'url' => $url,
            'channel' => (int) $channelNumber,
            'sort' => (float) $channelNumber,
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
        ]);

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
        if ($newGame !== $oldGame) {
            $info['twitch_game'] = $newGame;
            $info['twitch_game_box_art'] = $stream['game_box_art'] ?? '';
            $changed = true;
        }

        $login = strtolower($stream['login'] ?? '');
        $newLogo = $stream['profile_image'] ?: ($userProfiles[$login]['profile_image'] ?? '');
        if ($newLogo && $newLogo !== $channel->logo_internal) {
            $channel->logo_internal = $newLogo;
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
                    $results[$login] = [
                        'user_id' => (string) ($user['id'] ?? ''),
                        'display_name' => $user['display_name'] ?? $login,
                        'profile_image' => $user['profile_image_url'] ?? '',
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
     * @return list<array{login: string, display_name: string, user_id: string, title: string, game: string, game_box_art: string, thumbnail: string, profile_image: string}>
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
                ];
            }
        }

        return $results;
    }

    /**
     * Fetch recent VODs for a Twitch user via Helix API.
     *
     * @return list<array{id: string, title: string, thumbnail: string, duration: string}>
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

            $results[] = [
                'id' => (string) ($video['id'] ?? ''),
                'title' => $video['title'] ?? 'Untitled VOD',
                'thumbnail' => $thumbnail,
                'duration' => $video['duration'] ?? '',
            ];
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // Streamlink fallback
    // -------------------------------------------------------------------------

    /**
     * Check if a Twitch channel is live using streamlink --json.
     *
     * @return array{login: string, display_name: string, user_id: string, title: string, game: string, game_box_art: string, thumbnail: string, profile_image: string}|null
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
}
