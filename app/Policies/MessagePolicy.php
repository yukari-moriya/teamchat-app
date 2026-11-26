<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

/**
 * メッセージの権限（docs/design/permissions-api.md 1-3 の b）。拒否は 403（D-15）。
 * 「チャンネルを見られること」（D-16）は、ここより先にルートの ChannelPolicy の view が確かめる（見られなければ 404）。
 * ここで view を呼ばないのは、一覧の行ごとの @can で問い合わせが増えないようにするため。
 */
class MessagePolicy
{
    public function update(User $user, Message $message): bool
    {
        return $this->isOwnAndNotDeleted($user, $message);
    }

    public function delete(User $user, Message $message): bool
    {
        return $this->isOwnAndNotDeleted($user, $message);
    }

    private function isOwnAndNotDeleted(User $user, Message $message): bool
    {
        return $message->user_id === $user->id && ! $message->isDeleted();
    }
}
