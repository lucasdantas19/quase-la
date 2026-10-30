<?php

use App\Game\Game;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('game:expire', function (Game $game) {
    $game->expireDue();
})->purpose('Encerra rodadas cujo prazo expirou');
Schedule::command('game:expire')->everySecond();
