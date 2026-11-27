<?php

use App\Http\Controllers\ChannelController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MessageController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;

// ログイン・新規登録・ログアウトの処理は Fortify のコントローラ（D-01）。
// Fortify にルートを登録させない（FortifyServiceProvider）ので、docs/design/permissions-api.md 2-1 の No 1〜6 はここに書く

Route::get('/', fn () => redirect(auth()->check() ? '/channels' : '/login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/channels', [ChannelController::class, 'index'])->name('channels.index');
    Route::get('/channels/create', [ChannelController::class, 'create'])->name('channels.create');
    Route::post('/channels', [ChannelController::class, 'store'])->name('channels.store');

    // {channel} を使うルートは、まず見られるかを確かめる（見られなければ 404）。そのあとで作成者かを確かめる（403）。
    // 数字に限るのは、MySQL が「1abc」を 1 と読んで、そのチャンネルが開くのを防ぐため
    Route::middleware('can:view,channel')->whereNumber('channel')->group(function () {
        Route::get('/channels/{channel}', [ChannelController::class, 'show'])->name('channels.show');
        Route::get('/channels/{channel}/edit', [ChannelController::class, 'edit'])->name('channels.edit')
            ->can('update', 'channel');
        Route::put('/channels/{channel}', [ChannelController::class, 'update'])->name('channels.update')
            ->can('update', 'channel');
        Route::delete('/channels/{channel}', [ChannelController::class, 'destroy'])->name('channels.destroy')
            ->can('delete', 'channel');

        // メンバー管理は、プライベートチャンネルを作った人だけ（公開は作成者でも 404。D-23）
        Route::middleware('can:manageMembers,channel')->group(function () {
            Route::get('/channels/{channel}/members', [MemberController::class, 'index'])
                ->name('channels.members.index');
            Route::post('/channels/{channel}/members', [MemberController::class, 'store'])
                ->name('channels.members.store');
            // {user} がメンバーかは scopeBindings() で絞らない。権限の確認より先に動き、誰がメンバーかが分かるため。
            // 作成者かを確かめたあとに、removeMember で確かめる（D-35）
            Route::delete('/channels/{channel}/members/{user}', [MemberController::class, 'destroy'])
                ->name('channels.members.destroy')->whereNumber('user')
                ->can('removeMember', ['channel', 'user']);
        });

        Route::post('/channels/{channel}/messages', [MessageController::class, 'store'])
            ->name('channels.messages.store');
        // {message} は {channel} のものに限る（違えば 404）。そのあとで、本人のメッセージかを確かめる（403）
        Route::scopeBindings()->whereNumber('message')->group(function () {
            Route::put('/channels/{channel}/messages/{message}', [MessageController::class, 'update'])
                ->name('channels.messages.update')->can('update', 'message');
            Route::delete('/channels/{channel}/messages/{message}', [MessageController::class, 'destroy'])
                ->name('channels.messages.destroy')->can('delete', 'message');
        });
    });
});
