<?php

namespace AppLocalPlugins\Streamarr\Providers;

use AppLocalPlugins\Streamarr\Providers\DTO\MonitoredEntry;

/**
 * Holds all registered PlatformProvider instances. Resolution order matters:
 * specific providers (twitch, youtube, kick) are registered first and the
 * generic-streamlink / direct-url catch-alls last.
 */
class ProviderRegistry
{
    /** @var array<string,PlatformProvider> keyed by provider id */
    private array $providers = [];

    /** @var string[] insertion order — preserved for matches() probing */
    private array $order = [];

    public function register(PlatformProvider $provider): void
    {
        $id = $provider->id();
        if (! isset($this->providers[$id])) {
            $this->order[] = $id;
        }
        $this->providers[$id] = $provider;
    }

    public function has(string $id): bool
    {
        return isset($this->providers[$id]);
    }

    public function get(string $id): ?PlatformProvider
    {
        return $this->providers[$id] ?? null;
    }

    /**
     * @return PlatformProvider[]  in registration order
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->order as $id) {
            $out[] = $this->providers[$id];
        }

        return $out;
    }

    /**
     * Probe providers in registration order; first matches() => true wins.
     * Returns null when no provider claims the line.
     */
    public function resolve(string $rawLine): ?PlatformProvider
    {
        foreach ($this->order as $id) {
            $provider = $this->providers[$id];
            if ($provider->matches($rawLine)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Parse a list of raw textarea lines into MonitoredEntry objects, grouped by
     * provider id. Lines that don't match any provider are returned in the
     * `_unmatched` bucket as raw strings so the caller can surface a warning.
     *
     * @param  string[]  $lines
     * @return array{
     *     entries: array<string, MonitoredEntry[]>,
     *     unmatched: string[],
     * }
     */
    public function parseLines(array $lines): array
    {
        $entries = [];
        $unmatched = [];

        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $provider = $this->resolve($line);
            if (! $provider) {
                $unmatched[] = $line;

                continue;
            }

            $entry = $provider->parseEntry($line);
            if (! $entry) {
                $unmatched[] = $line;

                continue;
            }

            $entries[$provider->id()][] = $entry;
        }

        return [
            'entries' => $entries,
            'unmatched' => $unmatched,
        ];
    }
}
