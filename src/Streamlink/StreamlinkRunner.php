<?php

namespace AppLocalPlugins\Streamarr\Streamlink;

use Illuminate\Support\Facades\Process;

/**
 * Shared streamlink subprocess wrapper.
 *
 * Responsibilities:
 *  - locate the streamlink binary in well-known paths
 *  - run `streamlink --json <url>` against any provider URL and return the
 *    decoded payload (used by every PlatformProvider as a generic fallback)
 *  - keep all subprocess invocations consistent w.r.t. timeout, cookie file
 *    handling and additional CLI args
 *
 * AGENTS.md guardrail: no top-level executable code; all calls go through
 * Process::run() with an explicit timeout; binary discovery via findBinary().
 */
class StreamlinkRunner
{
    private ?string $cachedBinary = null;

    /** Locate the streamlink binary or null if not installed. */
    public function findBinary(): ?string
    {
        if ($this->cachedBinary !== null) {
            return $this->cachedBinary ?: null;
        }

        $candidates = [
            'streamlink',
            '/usr/local/bin/streamlink',
            '/usr/bin/streamlink',
            '/opt/venv/bin/streamlink',
            '/opt/homebrew/bin/streamlink',
        ];

        foreach ($candidates as $candidate) {
            // PATH lookup
            $which = $this->run(['which', $candidate], 5);
            if ($which['exit'] === 0 && trim($which['stdout']) !== '') {
                return $this->cachedBinary = trim($which['stdout']);
            }

            if (file_exists($candidate) && is_executable($candidate)) {
                return $this->cachedBinary = $candidate;
            }
        }

        $this->cachedBinary = '';

        return null;
    }

    /**
     * Run `streamlink --json <url>` (plus optional cookie file and extra args).
     * Returns decoded JSON or null on failure / streamlink "error" payload.
     *
     * @param  string[]  $extraArgs  additional CLI args appended after the URL
     * @return array<string,mixed>|null
     */
    public function probeJson(string $url, ?string $cookiesFile = null, array $extraArgs = [], int $timeoutSeconds = 30): ?array
    {
        $binary = $this->findBinary();
        if (! $binary) {
            return null;
        }

        $cmd = [$binary, '--json', $url];

        if ($cookiesFile && is_file($cookiesFile)) {
            $cmd[] = '--http-cookies-file';
            $cmd[] = $cookiesFile;
        }

        foreach ($extraArgs as $arg) {
            $cmd[] = (string) $arg;
        }

        $result = $this->run($cmd, $timeoutSeconds);

        if ($result['exit'] !== 0 || $result['stdout'] === '') {
            return null;
        }

        $json = json_decode($result['stdout'], true);
        if (! is_array($json)) {
            return null;
        }

        if (isset($json['error'])) {
            return null;
        }

        return $json;
    }

    /**
     * Generic live-detection used by streamlink-only providers.
     * Returns a normalized snapshot or null when offline / not found.
     *
     * @return array{title: string, author: ?string, category: ?string, id: ?string}|null
     */
    public function detectLive(string $url, ?string $cookiesFile = null, array $extraArgs = []): ?array
    {
        $json = $this->probeJson($url, $cookiesFile, $extraArgs);
        if (! $json) {
            return null;
        }

        $metadata = $json['metadata'] ?? [];

        return [
            'title' => (string) ($metadata['title'] ?? ''),
            'author' => isset($metadata['author']) ? (string) $metadata['author'] : null,
            'category' => isset($metadata['category']) ? (string) $metadata['category'] : null,
            'id' => isset($metadata['id']) ? (string) $metadata['id'] : null,
        ];
    }

    /**
     * @return array{exit:int,stdout:string,stderr:string}
     */
    public function run(array $cmd, int $timeoutSeconds = 30): array
    {
        $proc = Process::timeout($timeoutSeconds)->run($cmd);

        return [
            'exit' => $proc->exitCode(),
            'stdout' => (string) $proc->output(),
            'stderr' => (string) $proc->errorOutput(),
        ];
    }
}
