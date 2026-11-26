<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * メッセージと返信。いまは表と関連だけで、投稿・表示・写しの作成・削除済みの扱いは実装単位 4 で足す。
 * SoftDeletes は使わない（D-05）。
 */
class Message extends Model
{
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
