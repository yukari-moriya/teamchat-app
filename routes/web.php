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
});
