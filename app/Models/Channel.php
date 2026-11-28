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

    /** メンバー管理の一覧の並び。作成者が先頭、そのあとは追加した順。同じ日時なら利用者の ID の小さい順（D-34） */
    public function membersInListOrder(): BelongsToMany
    {
        return $this->members()
            ->orderByRaw('users.id = ? desc', [$this->creator_id])
            ->orderByPivot('created_at')
            ->orderBy('users.id');
    }

    /** 返信と削除済みも含む、このチャンネルのすべての行 */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** チャンネルに直接書かれたメッセージ。返信は含まず、削除済みは含む */
    public function topLevelMessages(): HasMany
    {
        return $this->messages()->whereNull('parent_id');
    }

    /**
     * thread の値（URL のクエリ・フォームの項目）が指す、スレッドの元のメッセージ。
     * このチャンネルの返信でないメッセージの ID でなければ null。値が null のときの扱いは、呼ぶ側が決める。
     */
    public function threadOrigin(mixed $id): ?Message
    {
        return $this->findByInputId($this->topLevelMessages(), $id);
    }

    /** edit の値が指す、このチャンネルの行（返信と削除済みも含む）。無ければ null */
    public function findMessage(mixed $id): ?Message
    {
        return $this->findByInputId($this->messages(), $id);
    }

    /** URL やフォームから受け取った ID で探す。数字に限るのは、MySQL が「1abc」を 1 と読むのを防ぐため */
    private function findByInputId(HasMany $messages, mixed $id): ?Message
    {
        return is_string($id) && ctype_digit($id) ? $messages->find($id) : null;
    }

    /**
     * 見られるチャンネル（公開はすべて＋自分がメンバーのプライベート）。
     * この条件はここにだけ書き、サイドバー・一覧・重複チェック・権限の判定はすべてここを通す
     * （docs/design/permissions-api.md 1-6）。
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $query) => $query
            ->public()
            ->orWhereHas('members', fn (Builder $members) => $members->whereKey($user->getKey())));
    }

    /** 公開チャンネル。「公開」で絞る条件はここにだけ書き、公開APIは、利用者を見ずにここを通す（1-3 の e） */
    public function scopePublic(Builder $query): void
    {
        $query->where('is_private', false);
    }

    /** 作成日時の古い順。同じ日時なら ID の小さい順（D-17） */
    public function scopeInListOrder(Builder $query): void
    {
        $query->orderBy('created_at')->orderBy('id');
    }
}
