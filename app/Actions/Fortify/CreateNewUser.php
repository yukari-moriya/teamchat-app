<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\TextNormalizer;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * 新規登録（F-01）。入力チェックの順と文言は docs/design/screens.md の4-1・4-2（SC-02）。
 * 表示名・メールアドレスの重複は、そろえた写しで比べる（D-12）。
 */
class CreateNewUser implements CreatesNewUsers
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        // 前後の空白・改行を取り除いてから見る（D-02）。パスワードは取り除かない。
        // 文字でない値（画面を通さずに配列を送ったとき）は、表示名とメールアドレスは空として扱う
        $name = TextNormalizer::trim(is_string($input['name'] ?? null) ? $input['name'] : '');
        $email = TextNormalizer::trim(is_string($input['email'] ?? null) ? $input['email'] : '');

        Validator::make(
            [
                'name' => $name,
                'email' => $email,
                'password' => $input['password'] ?? null,
                'password_confirmation' => $input['password_confirmation'] ?? null,
            ],
            [
                'name' => [
                    'bail',
                    'required',
                    'max:30',
                    fn ($attribute, $value, $fail) => preg_match('/[@＠]/u', $value) ? $fail('表示名に「@」は使えません') : null,
                    fn ($attribute, $value, $fail) => User::where('name_normalized', TextNormalizer::normalize($value))->exists()
                        ? $fail('その表示名はすでに使われています') : null,
                ],
                'email' => [
                    'bail',
                    'required',
                    'max:254',
                    'email',
                    fn ($attribute, $value, $fail) => User::where('email_normalized', TextNormalizer::normalize($value))->exists()
                        ? $fail('そのメールアドレスはすでに使われています') : null,
                ],
                // string が無いと、配列の min:8 は要素の数を数えて、8個以上の配列が通る
                'password' => ['bail', 'required', 'string', 'min:8'],
                'password_confirmation' => ['bail', 'required', 'same:password'],
            ],
            [
                'name.required' => '表示名を入力してください',
                'name.max' => '表示名は30文字以内で入力してください',
                'email.required' => 'メールアドレスを入力してください',
                'email.max' => 'メールアドレスは254文字以内で入力してください',
                'email.email' => 'メールアドレスの形式で入力してください',
                'password.required' => 'パスワードを入力してください',
                'password.string' => 'パスワードを入力してください',
                'password.min' => 'パスワードは8文字以上で入力してください',
                'password_confirmation.required' => 'パスワード（確認用）を入力してください',
                'password_confirmation.same' => 'パスワード（確認用）がパスワードと一致しません',
            ],
        )->validate();

        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => $input['password'],
        ]);
    }
}
