<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMessageRequest;
use App\Http\Requests\UpdateMessageRequest;
use App\Models\Channel;
use App\Models\Message;
use Illuminate\Http\RedirectResponse;

// 権限は routes/web.php の can ミドルウェアが、入力チェックより先に確かめる
class MessageController extends Controller
{
    public function store(StoreMessageRequest $request, Channel $channel): RedirectResponse
    {
        $message = new Message(['body' => $request->body()]);
        $message->user()->associate($request->user());
        $channel->messages()->save($message);

        return $this->redirectToRow($channel, $message);
    }

    public function update(UpdateMessageRequest $request, Channel $channel, Message $message): RedirectResponse
    {
        $message->edit($request->body());

        return $this->redirectToRow($channel, $message);
    }

    public function destroy(Channel $channel, Message $message): RedirectResponse
    {
        $message->markAsDeleted();

        return $this->redirectToRow($channel, $message);
    }

    /** 操作したあとは、その行の位置へ戻る（permissions-api.md 2-2） */
    private function redirectToRow(Channel $channel, Message $message): RedirectResponse
    {
        return to_route('channels.show', $channel)->withFragment($message->anchor());
    }
}
