<?php

namespace App\Support;

/**
 * 入力エラーのあとに、欄へ戻す前回の入力値。画面の欄は、old() をそのまま出さずに、ここを通す。
 * 画面を通さずに配列を送られると old() は配列を返し、そのまま出すと画面が 500 になるため。
 * 前回の入力値は欄ごとではなくまとめて持ち越すので、どの欄も通す。
 */
class OldInput
{
    /** 前回の入力が配列なら $default。入力を空にして送ったとき（null）は、$default に戻さず空にする */
    public static function text(string $key, ?string $default = null): string
    {
        $value = old($key, $default);

        return (string) (is_array($value) ? $default : $value);
    }
}
