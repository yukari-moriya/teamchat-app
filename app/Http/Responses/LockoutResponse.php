<?php

namespace App\Http\Responses;

use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\LockoutResponse as LockoutResponseContract;

/**
 * ログインの試行回数の上限を超えたとき、パスワードの欄の下に出す（D-28、screens.md 4-2）。
 */
class LockoutResponse implements LockoutResponseContract
{
    public function toResponse($request)
    {
        throw ValidationException::withMessages([
            'password' => ['ログインの試行回数が多すぎるため、しばらく待ってからもう一度ログインしてください'],
        ]);
    }
}
