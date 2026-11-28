<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * 検索結果の本文の、キーワードに当たった部分を <mark> で囲む（docs/design/screens.md 3-8 の No 7）。
 * 半角カナの濁点は前の文字とまとまるので、写しの列とは文字の位置がずれる。本文を区切ってそろえ直し、
 * 当たった位置を本文の側へ戻す（data.md の 2-4）。
 */
class KeywordHighlighter
{
    /**
     * 本文は書いたままの文字をエスケープして出し、足すタグは <mark> だけ。
     *
     * @param  list<string>  $keywords  TextNormalizer::keywords() の結果
     */
    public static function highlight(string $body, array $keywords): HtmlString
    {
        $units = self::units($body);

        // そろえた本文と、その何文字目が何番目の区切りかの対応
        $folded = '';
        $owners = [];

        foreach ($units as $index => $unit) {
            $part = TextNormalizer::fold($unit);
            $folded .= $part;
            array_push($owners, ...array_fill(0, mb_strlen($part), $index));
        }

        $marked = [];

        foreach ($keywords as $keyword) {
            $length = mb_strlen($keyword);

            // 1文字ずつ先へ進めて、重なって当たる位置も拾う
            for ($at = mb_strpos($folded, $keyword); $at !== false; $at = mb_strpos($folded, $keyword, $at + 1)) {
                foreach (array_slice($owners, $at, $length) as $index) {
                    $marked[$index] = true;
                }
            }
        }

        return new HtmlString(self::render($units, $marked));
    }

    /**
     * 本文を、そろえるときの単位に区切る。1文字ずつで、半角カナと後ろの濁点・半濁点が
     * まとまって1文字になる組だけは、1つにする。
     *
     * @return list<string>
     */
    private static function units(string $body): array
    {
        // 濁点・半濁点（ﾞ ﾟ）が続く半角カナは、いったん組にして取り出す
        preg_match_all('/[\x{FF66}-\x{FF9D}][\x{FF9E}\x{FF9F}]|./su', $body, $matches);

        $units = [];

        foreach ($matches[0] as $candidate) {
            // まとまらない組（ｱﾞ など）は、1文字ずつに分ける
            $isSplit = mb_strlen($candidate) === 2 && mb_strlen(TextNormalizer::fold($candidate)) !== 1;
            array_push($units, ...($isSplit ? mb_str_split($candidate) : [$candidate]));
        }

        return $units;
    }

    /**
     * 続けて印の付いた区切りは、まとめて1つの <mark> にする。
     *
     * @param  list<string>  $units
     * @param  array<int, true>  $marked  印を付ける区切りの番号
     */
    private static function render(array $units, array $marked): string
    {
        $runs = [];

        foreach ($units as $index => $unit) {
            $isMarked = isset($marked[$index]);
            $last = array_key_last($runs);

            if ($last !== null && $runs[$last]['marked'] === $isMarked) {
                $runs[$last]['text'] .= $unit;

                continue;
            }

            $runs[] = ['marked' => $isMarked, 'text' => $unit];
        }

        return implode('', array_map(
            fn (array $run) => $run['marked'] ? '<mark>'.e($run['text']).'</mark>' : e($run['text']),
            $runs
        ));
    }
}
