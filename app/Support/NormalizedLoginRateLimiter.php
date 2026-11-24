<?php

namespace App\Support;

use Illuminate\Http\Request;
use Laravel\Fortify\LoginRateLimiter;

/**
 * ログインの試行回数を、メールアドレスのそろえた写しと IP の組ごとに数える（D-28）。
 * 1分5回までは Fortify の LoginRateLimiter のまま。
 */
class NormalizedLoginRateLimiter extends LoginRateLimiter
{
    protected function throttleKey(Request $request)
    {
        return TextNormalizer::normalize((string) $request->input('email')).'|'.$request->ip();
    }
}
