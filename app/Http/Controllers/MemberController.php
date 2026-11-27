<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMemberRequest;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

// 権限は routes/web.php の can ミドルウェアが、入力チェックより先に確かめる
class MemberController extends Controller
{
    public function index(Channel $channel): View
    {
        return view('members.index', [
            'channel' => $channel,
            'members' => $channel->membersInListOrder()->get(),
        ]);
    }

    public function store(StoreMemberRequest $request, Channel $channel): RedirectResponse
    {
        // ほぼ同時の二重追加は、主キーで止めて 500 にする（docs/design/behavior.md 3-1 の #10）
        $channel->members()->attach($request->member());

        return to_route('channels.members.index', $channel);
    }

    public function destroy(Channel $channel, User $user): RedirectResponse
    {
        // 外すのはメンバーの行だけ。書いたメッセージは残る（F-13）
        $channel->members()->detach($user);

        return to_route('channels.members.index', $channel);
    }
}
