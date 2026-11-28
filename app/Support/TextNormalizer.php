<?php

namespace App\Support;

/**
 * 文字の比べ方（D-12・D-21）。そろえた写しを作る手順は docs/design/data.md の2章。
 * 重複チェック・ログインの照合・検索・メンバー追加は、すべてここを通す。
 */
class TextNormalizer
{
    /** 前後の空白・改行（全角の空白を含む）を取り除く（D-02） */
    public static function trim(string $value): string
    {
        return preg_replace('/^[\s　]+|[\s　]+$/u', '', $value) ?? '';
    }

    /** 前後の空白と改行を取り除いてから、そろえる */
    public static function normalize(string $value): string
    {
        return self::fold(self::trim($value));
    }

    /**
     * 検索のキーワード。そろえてから空白（全角の空白を含む）で区切り、空と重複を除く（data.md の2章）。
     * 検索の問い合わせと、結果の強調は、同じこの結果を使う。
     *
     * @return list<string>
     */
    public static function keywords(string $value): array
    {
        return array_values(array_unique(preg_split('/\s+/u', self::normalize($value), -1, PREG_SPLIT_NO_EMPTY)));
    }

    /** 文字をそろえる（data.md の2章の手順1〜4）。前後は取り除かない */
    public static function fold(string $value): string
    {
        // 1. 全角の英数字・記号を半角にする（U+FF01〜FF5E の範囲だけ。D-21）
        $value = preg_replace_callback(
            '/[\x{FF01}-\x{FF5E}]/u',
            fn (array $m) => mb_chr(mb_ord($m[0]) - 0xFEE0),
            $value
        ) ?? '';

        // 2. 全角の空白を半角にする
        $value = str_replace('　', ' ', $value);

        // 3. 半角カナを全角カナにする（濁点・半濁点は前の文字とまとめる。半角の長音・句読点も全角になる）
        $value = mb_convert_kana($value, 'KV');

        // 4. 英字を小文字にする
        return mb_strtolower($value);
    }
}
