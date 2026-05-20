<?php

namespace App\Services;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

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

    /**
     * Extract a single downloadable file_id from a Telegram message, if any.
     * Handles documents, photos (largest size), videos, audio, voice,
     * animations, and video notes.
     *
     * @return array{file_id: string, filename: string}|null
     */
    public static function extractTelegramFile(Message $message): ?array
    {
        $msgId = (int) ($message->message_id ?? 0);

        if (! empty($message->document?->file_id)) {
            return [
                'file_id' => $message->document->file_id,
                'filename' => self::nonEmpty($message->document->file_name) ?? "file-{$msgId}",
            ];
        }

        if (! empty($message->video?->file_id)) {
            return [
                'file_id' => $message->video->file_id,
                'filename' => self::nonEmpty($message->video->file_name) ?? "video-{$msgId}.mp4",
            ];
        }

        if (! empty($message->audio?->file_id)) {
            return [
                'file_id' => $message->audio->file_id,
                'filename' => self::nonEmpty($message->audio->file_name) ?? "audio-{$msgId}.mp3",
            ];
        }

        if (! empty($message->voice?->file_id)) {
            return [
                'file_id' => $message->voice->file_id,
                'filename' => "voice-{$msgId}.ogg",
            ];
        }

        if (! empty($message->animation?->file_id)) {
            return [
                'file_id' => $message->animation->file_id,
                'filename' => self::nonEmpty($message->animation->file_name) ?? "animation-{$msgId}.mp4",
            ];
        }

        if (! empty($message->video_note?->file_id)) {
            return [
                'file_id' => $message->video_note->file_id,
                'filename' => "videonote-{$msgId}.mp4",
            ];
        }

        if (is_array($message->photo ?? null) && $message->photo !== []) {
            $largest = end($message->photo);
            if (! empty($largest->file_id)) {
                return [
                    'file_id' => $largest->file_id,
                    'filename' => "photo-{$msgId}.jpg",
                ];
            }
        }

        return null;
    }

    private static function nonEmpty(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * Rewrite known source URLs to their canonical/working equivalents
     * before the download job runs.
     */
    public static function rewriteUrl(string $url): string
    {
        $prefixRewrites = [
            'https://cinema-tika.com/pages/dln/' => 'https://sv68.gcpgermany.com/dln/',
        ];

        foreach ($prefixRewrites as $from => $to) {
            if (str_starts_with($url, $from)) {
                return $to.substr($url, strlen($from));
            }
        }

        return $url;
    }
}
