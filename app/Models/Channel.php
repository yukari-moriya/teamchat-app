<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    use HasFactory;

    // is_private は作るときにだけ受け取る。更新では、名前と説明だけを渡す（F-08）
    protected $fillable = [
        'name',
        'description',
        'is_private',
    ];

    protected $casts = [
        'is_private' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /** プライベートチャンネルのメンバー。公開チャンネルには行を作らない（D-07） */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /** 返信と削除済みも含む、このチャンネルのすべての行 */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * 見られるチャンネル（公開はすべて＋自分がメンバーのプライベート）。
     * この条件はここにだけ書き、サイドバー・一覧・重複チェック・権限の判定はすべてここを通す
     * （docs/design/permissions-api.md 1-6）。
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('is_private', false)
            ->orWhereHas('members', fn (Builder $members) => $members->whereKey($user->getKey())));
    }

    /** 作成日時の古い順。同じ日時なら ID の小さい順（D-17） */
    public function scopeInListOrder(Builder $query): void
    {
        $query->orderBy('created_at')->orderBy('id');
    }
}
