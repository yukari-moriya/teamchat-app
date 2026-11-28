<?php

use App\Http\Controllers\Api\ChannelController;
use App\Http\Controllers\Api\MessageController;
use Illuminate\Support\Facades\Route;

// 公開API（docs/design/permissions-api.md 2-4・3章）。どれも認証なしの読み取りだけで、
// 守るのは返すデータの範囲（1-4）。URL の先頭には /api が付く
Route::get('/channels', [ChannelController::class, 'index'])->name('api.channels.index');

Route::get('/channels/{channel}/messages', [MessageController::class, 'index'])
    ->whereNumber('channel')
    ->name('api.channels.messages.index');
