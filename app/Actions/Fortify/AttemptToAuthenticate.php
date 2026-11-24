<?php

namespace App\Actions\Fortify;

use App\Support\TextNormalizer;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\AttemptToAuthenticate as FortifyAttemptToAuthenticate;

/**
 * ログインの照合。メールアドレスはそろえた写し（email_normalized）で探す（D-12）。
 * 失敗は、どちらが違うかを出さず、パスワードの欄の下に出す（F-02、screens.md 4-2）。
 */
class AttemptToAuthenticate extends FortifyAttemptToAuthenticate
{
    public function handle($request, $next)
    {
        $credentials = [
            'email_normalized' => TextNormalizer::normalize((string) $request->input('email')),
            'password' => $request->input('password'),
        ];

        if ($this->guard->attempt($credentials)) {
            return $next($request);
        }

        $this->limiter->increment($request);

        throw ValidationException::withMessages([
            'password' => ['メールアドレスまたはパスワードが正しくありません'],
        ]);
    }
}
