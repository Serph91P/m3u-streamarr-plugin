<?php

declare(strict_types=1);

/**
 * Test script: validates that fetchProfileImageFallback() logic
 * can extract Twitch profile images without API credentials.
 *
 * Usage: php scripts/test-profile-image-fallback.php [username ...]
 */

$testLogins = array_slice($argv, 1) ?: ['nightbot', 'pokimane', 'montanablack88'];

echo "Testing profile image fallback for " . count($testLogins) . " channel(s)...\n\n";

$passed = 0;
$failed = 0;

foreach ($testLogins as $login) {
    $login = strtolower(trim($login));
    echo "  [{$login}] Fetching https://www.twitch.tv/{$login} ... ";

    $result = fetchProfileImageFallback($login);

    if ($result !== '') {
        echo "OK\n";
        echo "    Logo URL: {$result}\n";

        // Validate the URL is actually reachable
        $headers = @get_headers($result, true);
        $status = $headers[0] ?? '';
        if (str_contains($status, '200')) {
            echo "    Image reachable: YES (200 OK)\n";
            $passed++;
        } else {
            echo "    Image reachable: NO ({$status})\n";
            $failed++;
        }
    } else {
        echo "FAIL - no profile image found\n";
        $failed++;
    }

    echo "\n";
}

echo str_repeat('-', 50) . "\n";
echo "Results: {$passed} passed, {$failed} failed out of " . count($testLogins) . " channel(s)\n";

if ($failed > 0) {
    exit(1);
}

echo "All profile image fallbacks working correctly.\n";
exit(0);

// ---------------------------------------------------------------------------
// Extracted fallback logic (mirrors Plugin::fetchProfileImageFallback)
// ---------------------------------------------------------------------------

function fetchProfileImageFallback(string $login): string
{
    $url = "https://www.twitch.tv/{$login}";

    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'header' => "User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36\r\n",
        ],
    ]);

    $html = @file_get_contents($url, false, $context);

    if ($html === false || $html === '') {
        return '';
    }

    // Best match: Twitch profile image URL (contains "profile_image" in the CDN path)
    if (preg_match('#https://static-cdn\.jtvnw\.net/jtv_user_pictures/[^"\'\s]+profile_image[^"\'\s]*#', $html, $m)) {
        // Downsize to 70x70 — sufficient for channel logos and saves bandwidth
        return preg_replace('#-\d+x\d+\.#', '-70x70.', $m[0]);
    }

    // Fallback: og:image meta tag
    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) {
        return $m[1];
    }

    if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/', $html, $m)) {
        return $m[1];
    }

    return '';
}
