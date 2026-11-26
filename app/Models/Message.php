<?php

namespace App\Models;

use App\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * メッセージと返信（返信は parent_id で元のメッセージを指す。D-06）。
 * 削除は行を残して deleted_at を入れるだけで、SoftDeletes は使わない（D-05）。
 */
class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'body',
    ];

    protected $casts = [
        'is_edited' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // 写しの列（docs/design/data.md 2-4）は、保存のたびに本文から作る
        static::saving(function (Message $message) {
            $message->body_normalized = TextNormalizer::normalize($message->body);
        });
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** 投稿者 */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** このメッセージへの返信。削除された返信も含む（返信件数に数える。Q-18） */
    public function replies(): HasMany
    {
        return $this->hasMany(Message::class, 'parent_id');
    }

    /**
     * 削除済みを除く。画面は削除済みも取り出すので使わない。
     * 検索と公開APIは、条件を自分で書かず、ここを通す（D-05 の補足のリスク2・3）。
     */
    public function scopeNotDeleted(Builder $query): void
    {
        $query->whereNull('deleted_at');
    }

    /** 古い順。同じ日時なら ID の小さい順（R-07） */
    public function scopeInListOrder(Builder $query): void
    {
        $query->orderBy('created_at')->orderBy('id');
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    /** 本文を直す。本文が変わらないときは、何も変えない（D-25）。「編集済み」は戻さない（R-05） */
    public function edit(string $body): void
    {
        if ($body === $this->body) {
            return;
        }

        $this->body = $body;
        $this->is_edited = true;
        $this->save();
    }

    /** 削除の印を付ける。行も本文も写しも残す（D-05） */
    public function markAsDeleted(): void
    {
        $this->deleted_at = $this->freshTimestamp();
        $this->save();
    }

    /** チャンネルの画面の中の、この行の位置の名前（URL の # で指す。permissions-api.md 2-2） */
    public function anchor(): string
    {
        return "message-{$this->id}";
    }
}
