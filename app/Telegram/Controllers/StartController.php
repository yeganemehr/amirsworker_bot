<?php

namespace App\Telegram\Controllers;

use SergiX44\Nutgram\Nutgram;

class StartController
{
    public function __invoke(Nutgram $bot): void
    {
        $bot->sendMessage(
            "Send me one URL per line, or upload a file.\n".
            'I will store each one and reply with a public link.'
        );
    }
}
