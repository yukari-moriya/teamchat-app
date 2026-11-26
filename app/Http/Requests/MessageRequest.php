<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * メッセージの本文の入力チェック。順と文言は docs/design/screens.md の4-2（SC-04）。
 * 本文の欄は画面に複数同時に出るので、項目名は欄ごとに分ける（permissions-api.md 2-3）。
 * 前後の空白と改行を取り除くのと、空を NULL にするのは、全体のミドルウェアが済ませている（D-02）。
 * 権限は、ルートの can ミドルウェアで確かめる。
 */
abstract class MessageRequest extends FormRequest
{
    /** この画面の欄の項目名 */
    abstract protected function field(): string;

    protected function prepareForValidation(): void
    {
        $body = $this->input($this->field());

        // ブラウザは改行を CRLF で送るので、1つの改行が2文字に数えられないようにそろえる
        if (is_string($body)) {
            $this->merge([$this->field() => str_replace("\r\n", "\n", $body)]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            $this->field() => ['bail', 'required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            $this->field().'.required' => 'メッセージを入力してください',
            $this->field().'.string' => 'メッセージを入力してください',
            $this->field().'.max' => 'メッセージは1,000文字以内で入力してください',
        ];
    }

    /** 保存する本文（入力チェックを通ったもの） */
    public function body(): string
    {
        return $this->validated($this->field());
    }
}
