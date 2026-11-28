<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 検索のキーワードの入力チェック。文言は docs/design/screens.md の4-2（SC-09）。文字数の上限は無い。
 * q が無いときは調べない（入力欄だけを出す。permissions-api.md 2-2）。
 * 前後の空白を取り除くのと、空を NULL にするのは、全体のミドルウェアが済ませている（D-02）。
 */
class SearchRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['bail', 'sometimes', 'required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.required' => 'キーワードを入力してください',
            'q.string' => 'キーワードを入力してください',
        ];
    }

    /** エラーは、どの画面から送っても SC-09 に出す（D-02）。q を付けないので、エラーは繰り返さない */
    protected function getRedirectUrl(): string
    {
        return route('search');
    }
}
