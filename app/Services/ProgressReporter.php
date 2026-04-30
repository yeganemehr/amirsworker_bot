<?php

namespace App\Services;

use SergiX44\Nutgram\Nutgram;
use Throwable;

class ProgressReporter
{
    private float $lastEditAt = 0.0;

    private string $lastText = '';

    public function __construct(
        private readonly Nutgram $bot,
        private readonly int $chatId,
        private readonly int $messageId,
        private readonly int $intervalMs,
    ) {}

    public function report(string $text, bool $force = false): void
    {
        $now = microtime(true) * 1000;

        if (! $force && ($now - $this->lastEditAt) < $this->intervalMs) {
            return;
        }

        if ($text === $this->lastText) {
            return;
        }

        try {
            $this->bot->editMessageText(
                text: $text,
                chat_id: $this->chatId,
                message_id: $this->messageId,
                disable_web_page_preview: true,
            );
            $this->lastText = $text;
            $this->lastEditAt = $now;
        } catch (Throwable) {
            // swallow "message is not modified" / 429 / transient errors;
            // progress is best-effort.
            $this->lastEditAt = $now;
        }
    }

    public static function renderBar(int $total, int $current, int $width = 20): string
    {
        if ($total <= 0) {
            return str_repeat('·', $width).' '.self::formatBytes($current);
        }

        $ratio = max(0.0, min(1.0, $current / $total));
        $filled = (int) round($ratio * $width);
        $bar = str_repeat('█', $filled).str_repeat('░', $width - $filled);

        return sprintf(
            '%s %d%% (%s / %s)',
            $bar,
            (int) round($ratio * 100),
            self::formatBytes($current),
            self::formatBytes($total),
        );
    }

    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) $bytes;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $v, $units[$i]);
    }
}
