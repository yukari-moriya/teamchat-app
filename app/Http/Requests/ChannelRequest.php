<?php

namespace App\Http\Requests;

use App\Rules\UniqueChannelName;
use Illuminate\Foundation\Http\FormRequest;

/**
 * チャンネルの名前と説明（作成と編集で共通）。順と文言は docs/design/screens.md の4-2（SC-06・SC-07）。
 * 前後の空白を取り除くのと、空を NULL にするのは、全体のミドルウェアが済ませている。
 * 権限は、ルートの can ミドルウェアで確かめる。
 */
class ChannelRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $description = $this->input('description');

        // ブラウザは改行を CRLF で送るので、1つの改行が2文字に数えられないようにそろえる
        if (is_string($description)) {
            $this->merge(['description' => str_replace("\r\n", "\n", $description)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:50', new UniqueChannelName($this->user(), $this->route('channel'))],
            'description' => ['bail', 'nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'チャンネル名を入力してください',
            'name.string' => 'チャンネル名を入力してください',
            'name.max' => 'チャンネル名は50文字以内で入力してください',
            'description.string' => '説明は200文字以内で入力してください',
            'description.max' => '説明は200文字以内で入力してください',
        ];
    }
}
