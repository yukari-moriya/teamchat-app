<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\TextNormalizer;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * メンバーの追加。順と文言は docs/design/screens.md の4-2（SC-08）。
 * 権限は、ルートの can ミドルウェアで確かめる。
 */
class StoreMemberRequest extends FormRequest
{
    private ?User $member = null;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'member' => ['bail', 'required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                $this->member = $this->findUser($value);

                if ($this->member === null) {
                    $fail('一致する人が見つかりません');

                    return;
                }

                if ($this->route('channel')->members()->whereKey($this->member->getKey())->exists()) {
                    $fail('その人はすでにメンバーです');
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
            'member.required' => '表示名またはメールアドレスを入力してください',
            'member.string' => '表示名またはメールアドレスを入力してください',
        ];
    }

    /** 入力に一致した、追加する人。入力チェックを通ったあとで使う */
    public function member(): User
    {
        return $this->member;
    }

    /** 「@」があればメールアドレスとだけ、無ければ表示名とだけ、そろえた写しの完全一致で比べる（D-12・D-24） */
    private function findUser(string $input): ?User
    {
        $value = TextNormalizer::normalize($input);
        $column = str_contains($value, '@') ? 'email_normalized' : 'name_normalized';

        return User::where($column, $value)->first();
    }
}
