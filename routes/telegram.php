<?php
/** @var SergiX44\Nutgram\Nutgram $bot */

use App\Jobs\ProcessUrlsJob;
use App\Services\BotHelpers;
use SergiX44\Nutgram\Nutgram;

$bot->onCommand('start', function (Nutgram $bot) {
    if (! BotHelpers::isAuthorized($bot)) {
        return;
    }

    $bot->sendMessage(
        "Send me one URL per line.\n".
        'I will download each file, store it, and reply with a link.'
    );
})->description('Show usage');

$bot->onMessage(function (Nutgram $bot) {
    if (! BotHelpers::isAuthorized($bot)) {
        return;
    }

    $message = $bot->message();
    if ($message === null) {
        return;
    }

    $text = trim((string) ($message->text ?? ''));
    if ($text === '' || str_starts_with($text, '/')) {
        return;
    }

    $urls = BotHelpers::extractUrls($text);

    if ($urls === []) {
        $bot->sendMessage('No valid URLs found. Send one URL per line.');

        return;
    }

    $count = count($urls);
    $status = $bot->sendMessage("⏳ Queued {$count} URL".($count === 1 ? '' : 's').'…');

    if ($status === null) {
        return;
    }

    ProcessUrlsJob::dispatch(
        $message->chat->id,
        $status->message_id,
        $message->from->id,
        $urls,
    );
});
