<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChannelRequest;
use App\Http\Requests\DestroyChannelRequest;
use App\Http\Requests\StoreChannelRequest;
use App\Models\Channel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

// 権限は routes/web.php の can ミドルウェアが、入力チェックより先に確かめる
class ChannelController extends Controller
{
    public function index(Request $request): View
    {
        return view('channels.index', [
            'channels' => Channel::visibleTo($request->user())->inListOrder()->with('creator')->get(),
        ]);
    }

    public function create(): View
    {
        return view('channels.create');
    }

    public function store(StoreChannelRequest $request): RedirectResponse
    {
        // channels と、作成者のメンバーの行は、両方書けるか、両方書かないか（docs/design/behavior.md 3-2）
        $channel = DB::transaction(function () use ($request) {
            $channel = new Channel([
                ...$request->safe()->only(['name', 'description']),
                'is_private' => $request->validated('visibility') === 'private',
            ]);
            $channel->creator()->associate($request->user());
            $channel->save();

            if ($channel->is_private) {
                $channel->members()->attach($request->user());
            }

            return $channel;
        });

        return to_route('channels.show', $channel);
    }

    public function show(Channel $channel): View
    {
        return view('channels.show', ['channel' => $channel]);
    }

    public function edit(Request $request, Channel $channel): View
    {
        $confirmDelete = $request->query('confirm_delete') === '1';

        // 確認に出すのは、そのときの実際の件数。削除済みも数える（Q-28、D-05）
        if ($confirmDelete) {
            $channel->loadCount([
                'messages as message_count' => fn ($query) => $query->whereNull('parent_id'),
                'messages as reply_count' => fn ($query) => $query->whereNotNull('parent_id'),
            ]);
        }

        return view('channels.edit', ['channel' => $channel, 'confirmDelete' => $confirmDelete]);
    }

    public function update(ChannelRequest $request, Channel $channel): RedirectResponse
    {
        $channel->update($request->safe()->only(['name', 'description']));

        return to_route('channels.show', $channel);
    }

    public function destroy(DestroyChannelRequest $request, Channel $channel): RedirectResponse
    {
        // メッセージ・返信・メンバーの行は、外部キーの CASCADE で一緒に消える
        $channel->delete();

        return to_route('channels.index');
    }
}
