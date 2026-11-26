<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * チャンネルの削除の確認。入力とチャンネル名は完全一致で比べる（D-19。D-12 のそろえ方は当てない）。
 */
class DestroyChannelRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'confirm_name' => ['bail', 'required', function (string $attribute, mixed $value, Closure $fail) {
                if ($value !== $this->route('channel')->name) {
                    $fail('チャンネル名が一致しません');
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm_name.required' => 'チャンネル名を入力してください',
        ];
    }

    /** エラーのときは、削除の確認を開いた状態へ戻す（docs/design/screens.md 3-6 No 12） */
    protected function getRedirectUrl(): string
    {
        return route('channels.edit', ['channel' => $this->route('channel'), 'confirm_delete' => 1]);
    }
}
