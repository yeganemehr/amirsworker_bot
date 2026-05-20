<?php

namespace App\Telegram\Controllers;

use App\Jobs\ProcessUrlJob;
use App\Services\BotHelpers;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

class MessageController
{
    public function handle(Nutgram $bot): void
    {
        $message = $bot->message();
        if ($message === null) {
            return;
        }

        $file = BotHelpers::extractTelegramFile($message);
        if ($file !== null) {
            $this->queueFile($bot, $message, $file);

            return;
        }

        $text = trim((string) ($message->text ?? ''));
        if ($text === '' || str_starts_with($text, '/')) {
            return;
        }

        $urls = BotHelpers::extractUrls($text);

        if ($urls === []) {
            $bot->sendMessage('No valid URLs or files found. Send one URL per line, or upload a file.');

            return;
        }

        foreach ($urls as $url) {
            $this->queueUrl($bot, $message, BotHelpers::rewriteUrl($url));
        }
    }

    /**
     * @param  array{file_id: string, filename: string}  $file
     */
    private function queueFile(Nutgram $bot, Message $message, array $file): void
    {
        $status = $bot->sendMessage("⏳ Queued…\n{$file['filename']}");
        if ($status === null) {
            return;
        }

        ProcessUrlJob::dispatch(
            $message->chat->id,
            $status->message_id,
            $message->from->id,
            null,
            $file['file_id'],
            $file['filename'],
        );
    }

    private function queueUrl(Nutgram $bot, Message $message, string $url): void
    {
        $status = $bot->sendMessage("⏳ Queued…\n{$url}");
        if ($status === null) {
            return;
        }

        ProcessUrlJob::dispatch(
            $message->chat->id,
            $status->message_id,
            $message->from->id,
            $url,
        );
    }
}
