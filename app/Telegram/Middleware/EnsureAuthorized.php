<?php

namespace App\Telegram\Middleware;

use App\Services\BotHelpers;
use SergiX44\Nutgram\Nutgram;

class EnsureAuthorized
{
    public function __invoke(Nutgram $bot, callable $next): void
    {
        if (! BotHelpers::isAuthorized($bot)) {
            return;
        }

        $next($bot);
    }
}
