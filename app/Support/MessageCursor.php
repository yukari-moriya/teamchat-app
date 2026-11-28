<?php

namespace App\Support;

use DateTimeImmutable;
use stdClass;

/**
 * 公開APIのメッセージ一覧の cursor の値の確かめ（docs/design/permissions-api.md 3-3、D-42）。
 * Laravel は形を確かめずに使うので、壊れ方によっては 500 になったり、一部の行だけが返ったりする。
 * Laravel の Cursor::fromEncoded は、中身が数値だとそれ自体が失敗するので、ここでは使わない。
 */
class MessageCursor
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    /** 並びの列（created_at・id）と、ページ送りの向き。名前の順に並べて持つ */
    private const KEYS = ['_pointsToNextItems', 'created_at', 'id'];

    /** meta.next_cursor・meta.prev_cursor で返す値の形か */
    public static function isValid(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        // 戻し方は Laravel とそろえる（URL 用の - と _ を、+ と / に戻す）
        $json = base64_decode(strtr($value, '-_', '+/'), true);

        if ($json === false) {
            return false;
        }

        $decoded = json_decode($json);

        if (! $decoded instanceof stdClass) {
            return false;
        }

        $parameters = (array) $decoded;
        $keys = array_map('strval', array_keys($parameters));
        sort($keys);

        return $keys === self::KEYS
            && self::isDateTime($parameters['created_at'])
            && is_int($parameters['id'])
            && is_bool($parameters['_pointsToNextItems']);
    }

    /** 実在する日時か。形を先に確かめるのは、NUL を含む文字列を渡すと、日時の読み取りが例外になるため */
    private static function isDateTime(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $value) !== 1) {
            return false;
        }

        // 2月30日などは繰り上げて読まれるので、書き戻して同じになるかを見る
        $date = DateTimeImmutable::createFromFormat('!'.self::DATE_FORMAT, $value);

        return $date !== false && $date->format(self::DATE_FORMAT) === $value;
    }
}
