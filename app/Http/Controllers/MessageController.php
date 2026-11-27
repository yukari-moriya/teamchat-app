<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMessageRequest;
use App\Http\Requests\UpdateMessageRequest;
use App\Models\Channel;
use App\Models\Message;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

// 権限は routes/web.php の can ミドルウェアが、入力チェックより先に確かめる
class MessageController extends Controller
{
    public function store(StoreMessageRequest $request, Channel $channel): RedirectResponse
    {
        $message = new Message(['body' => $request->body()]);
        $message->user()->associate($request->user());
        $channel->messages()->save($message);

        return $this->redirectToRow($request, $channel, $message);
    }

    public function update(UpdateMessageRequest $request, Channel $channel, Message $message): RedirectResponse
    {
        $message->edit($request->body());

        return $this->redirectToRow($request, $channel, $message);
    }

    public function destroy(Request $request, Channel $channel, Message $message): RedirectResponse
    {
        $message->markAsDeleted();

        return $this->redirectToRow($request, $channel, $message);
    }

    /**
     * 操作したあとは、その行の位置へ戻る。開いていたスレッドは開いたままにする（permissions-api.md 2-2）。
     * フォームの thread がスレッドの元を指していなければ、無いのと同じに扱う（D-37）。
     */
    private function redirectToRow(Request $request, Channel $channel, Message $message): RedirectResponse
    {
        return redirect($message->url($channel->threadOrigin($request->input('thread'))?->id));
    }
}
