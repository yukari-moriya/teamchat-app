<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChannelRequest;
use App\Http\Requests\DestroyChannelRequest;
use App\Http\Requests\StoreChannelRequest;
use App\Models\Channel;
use App\Models\Message;
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

    public function show(Request $request, Channel $channel): View
    {
        $thread = $this->openThread($request, $channel);

        return view('channels.show', [
            'channel' => $channel,
            // チャンネルの流れに返信は混ぜない。削除済みも取り出し、返信件数は削除された返信も数える（D-05、Q-18）
            'messages' => $channel->topLevelMessages()->inListOrder()
                ->with('user')->withCount('replies')->get(),
            // 開いているスレッドの元のメッセージ。開いていなければ null
            'thread' => $thread,
            'editingMessageId' => $this->editingMessageId($request, $channel, $thread),
        ]);
    }

    /**
     * ?thread= で開くスレッド（docs/design/permissions-api.md 2-2）。
     * このチャンネルに無いIDと、返信のIDは 404。削除されたメッセージは開ける（R-06）。
     */
    private function openThread(Request $request, Channel $channel): ?Message
    {
        $id = $request->query('thread');

        if ($id === null) {
            return null;
        }

        $origin = $channel->threadOrigin($id);
        abort_if($origin === null, 404);

        return $origin->load([
            'user',
            'replies' => fn ($query) => $query->inListOrder()->with('user'),
        ]);
    }

    /**
     * ?edit= で編集中にするメッセージ（docs/design/permissions-api.md 2-2）。
     * このチャンネルに無いIDは 404。あっても直せないもの（他人の・削除済み）は、通常の表示にする。
     * 返信は、その返信の元のスレッドを開いていなければ、通常の表示にする（D-26）。
     */
    private function editingMessageId(Request $request, Channel $channel, ?Message $thread): ?int
    {
        $id = $request->query('edit');

        if ($id === null) {
            return null;
        }

        $message = $channel->findMessage($id);
        abort_if($message === null, 404);

        if ($message->isReply() && $message->parent_id !== $thread?->id) {
            return null;
        }

        return $request->user()->can('update', $message) ? $message->id : null;
    }

    public function edit(Request $request, Channel $channel): View
    {
        $confirmDelete = $request->query('confirm_delete') === '1';

        // 確認に出すのは、そのときの実際の件数。削除済みも数える（Q-28、D-05）
        if ($confirmDelete) {
            $channel->loadCount([
                'topLevelMessages as message_count',
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
