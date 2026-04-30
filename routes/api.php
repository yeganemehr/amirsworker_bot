<?php

use Illuminate\Support\Facades\Route;
use SergiX44\Nutgram\Nutgram;

Route::post('/webhook', fn (Nutgram $bot) => $bot->run());
