<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReplyRequest;
use App\Models\Channel;
use App\Models\Message;
use Illuminate\Http\RedirectResponse;

/**
 * 返信を書く。返信はメッセージと同じ表に持つので（D-06）、モデルは Message。直す・消すは MessageController。
 */
class ReplyController extends Controller
{
    public function store(StoreReplyRequest $request, Channel $channel, Message $message): RedirectResponse
    {
        $reply = new Message(['body' => $request->body()]);
        $reply->user()->associate($request->user());
        // 返信も、元のメッセージと同じチャンネルに入れる（data.md 2-4）。parent_id は replies() が入れる
        $reply->channel()->associate($channel);
        $message->replies()->save($reply);

        return redirect($reply->url($message->id));
    }
}
