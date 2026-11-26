<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * チャンネルの作成。公開範囲は作るときにだけ受け取る（F-08）。
 */
class StoreChannelRequest extends ChannelRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'visibility' => ['bail', 'required', Rule::in(['public', 'private'])],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'visibility.required' => '公開範囲を選んでください',
            'visibility.in' => '公開範囲を選んでください',
        ];
    }
}
