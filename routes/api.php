<?php

use App\Http\Controllers\GameController;
use Illuminate\Support\Facades\Route;

Route::post('/rooms', [GameController::class, 'create'])->middleware('throttle:admission');
Route::post('/rooms/join', [GameController::class, 'join'])->middleware('throttle:admission');
Route::middleware('auth:participant')->group(function () {
    Route::get('/rounds/{number}', [GameController::class, 'history'])->whereNumber('number')->middleware('throttle:state');
    Route::get('/room', [GameController::class, 'state'])->middleware('throttle:state');
    Route::post('/room/leave', [GameController::class, 'leave'])->middleware('throttle:actions');
    Route::post('/competition/{action}', [GameController::class, 'competition'])->middleware('throttle:actions');
    Route::post('/room/{action}', [GameController::class, 'action'])->middleware('throttle:actions');
});
