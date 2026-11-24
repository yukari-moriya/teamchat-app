<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

/**
 * 登録のあとは、いつもチャンネル一覧へ（Q-04）。Fortify の既定は「開こうとしていたページ」を優先する。
 */
class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request)
    {
        return redirect(config('fortify.home'));
    }
}
