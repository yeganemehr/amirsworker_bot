<?php

namespace App\Services;

use SergiX44\Nutgram\Nutgram;

class BotHelpers
{
    public static function isAuthorized(Nutgram $bot): bool
    {
        $allowed = config('app.allowed_ids', []);
        if ($allowed === []) {
            return true;
        }

        $userId = $bot->userId();

        return $userId !== null && in_array((int) $userId, $allowed, true);
    }

    /**
     * @return array<int, string>
     */
    public static function extractUrls(string $text): array
    {
        $lines = preg_split('/\R+/', $text) ?: [];
        $urls = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (! preg_match('#^https?://#i', $line)) {
                continue;
            }
            if (filter_var($line, FILTER_VALIDATE_URL) === false) {
                continue;
            }
            $urls[] = $line;
        }

        return $urls;
    }
}
