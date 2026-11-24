<?php

namespace App\Providers;

use App\Actions\Fortify\AttemptToAuthenticate;
use App\Actions\Fortify\CreateNewUser;
use App\Http\Responses\LockoutResponse;
use App\Http\Responses\RegisterResponse;
use App\Support\NormalizedLoginRateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts\LockoutResponse as LockoutResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Fortify が登録するルートには、設計書に無いもの（パスワードの確認）が混ざるので、使うルートは routes/web.php に書く
        Fortify::ignoreRoutes();

        // 試行回数の上限（D-28）: 数える組と、超えたときのエラーの出し方を、設計書に合わせる
        $this->app->singleton(LoginRateLimiter::class, NormalizedLoginRateLimiter::class);
        $this->app->singleton(LockoutResponseContract::class, LockoutResponse::class);

        // 登録のあとは、開こうとしていたページではなく、いつも /channels（Q-04）
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
    }

    public function boot(): void
    {
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::registerView(fn () => view('auth.register'));
        Fortify::createUsersUsing(CreateNewUser::class);

        // ログインの流れ。照合を写しで行い、失敗をパスワードの欄に出すため、AttemptToAuthenticate だけ差し替える
        Fortify::authenticateThrough(fn () => [
            EnsureLoginIsNotThrottled::class,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]);
    }
}
