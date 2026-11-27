<?php

namespace App\Policies;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * チャンネルの権限（docs/design/permissions-api.md 1-5・1-6）。権限の判定はここにだけ書く。
 * view 以外は、view を通ったあと（見られるチャンネル）で使う。拒否は 403（D-15）。
 */
class ChannelPolicy
{
    /** 見られないときは、存在しないチャンネルと同じ 404（R-01） */
    public function view(User $user, Channel $channel): Response
    {
        return Channel::visibleTo($user)->whereKey($channel->getKey())->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, Channel $channel): bool
    {
        return $this->isCreator($user, $channel);
    }

    public function delete(User $user, Channel $channel): bool
    {
        return $this->isCreator($user, $channel);
    }

    /** 公開チャンネルにはメンバー管理が無いので、作成者かどうかより先に見て 404（D-23） */
    public function manageMembers(User $user, Channel $channel): Response
    {
        if (! $channel->is_private) {
            return Response::denyAsNotFound();
        }

        return $this->isCreator($user, $channel) ? Response::allow() : Response::deny();
    }

    /**
     * manageMembers の条件（404・403 は同じ）に加えて、作成者は外せない（403。R-04・D-33）。
     * 相手がメンバーでなければ 404。これを作成者かより先に確かめると、403 との違いで誰がメンバーかが分かる（D-35）。
     */
    public function removeMember(User $user, Channel $channel, User $member): Response
    {
        $manage = $this->manageMembers($user, $channel);

        if ($manage->denied()) {
            return $manage;
        }

        if ($this->isCreator($member, $channel)) {
            return Response::deny();
        }

        // 読み込んだ関連で調べるのは、SC-08 の行ごとの @can で問い合わせが増えないようにするため
        return $channel->members->contains($member) ? Response::allow() : Response::denyAsNotFound();
    }

    private function isCreator(User $user, Channel $channel): bool
    {
        return $channel->creator_id === $user->id;
    }
}
