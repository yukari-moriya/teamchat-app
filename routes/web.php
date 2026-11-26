<?php

use App\Http\Controllers\ChannelController;
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
    });
});
