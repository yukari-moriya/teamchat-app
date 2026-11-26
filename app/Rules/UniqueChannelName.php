<?php

namespace App\Rules;

use App\Models\Channel;
use App\Models\User;
use App\Support\TextNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * チャンネル名の重複。見られるチャンネルの中だけで確かめる（Q-32）。
 * 写しの列は持たないので、両方の名前をここでそろえて比べる（D-12、docs/design/data.md 2-2）。
 */
class UniqueChannelName implements ValidationRule
{
    public function __construct(
        private readonly User $user,
        private readonly ?Channel $except = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 編集で名前が今のまま（完全一致）なら調べない。同じ名前がほかに見えていても、説明を直せるように（D-31）
        if ($this->except?->name === $value) {
            return;
        }

        $target = TextNormalizer::normalize($value);

        $taken = Channel::visibleTo($this->user)
            ->when($this->except, fn ($query) => $query->whereKeyNot($this->except->getKey()))
            ->pluck('name')
            ->contains(fn (string $name) => TextNormalizer::normalize($name) === $target);

        if ($taken) {
            $fail('そのチャンネル名はすでに使われています');
        }
    }
}
