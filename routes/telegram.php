<?php

/** @var Nutgram $bot */

use App\Telegram\Controllers\MessageController;
use App\Telegram\Controllers\StartController;
use App\Telegram\Middleware\EnsureAuthorized;
use SergiX44\Nutgram\Nutgram;

$bot->middleware(EnsureAuthorized::class);

$bot->onCommand('start', StartController::class)->description('Show usage');

$bot->onMessage([MessageController::class, 'handle']);
