<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Channel;
use App\Support\MessageCursor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** 公開API（F-24）。呼んだのが誰かは見ず、返す範囲を絞る（docs/design/permissions-api.md 1-4） */
class MessageController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request, string $channel): AnonymousResourceCollection
    {
        // 暗黙のモデル結合は使わない。公開チャンネルの中だけから探し、無ければ理由を問わず 404（Q-27）
        $channel = Channel::public()->findOrFail($channel);

        // 壊れた cursor は、無いのと同じに扱う（D-39）。
        // cursorPaginate に null を渡しても Laravel がリクエストから読み直すので、リクエストの値を消す
        if (! MessageCursor::isValid($request->input('cursor'))) {
            $request->merge(['cursor' => null]);
        }

        // 並びの列名は cursor の項目名になるので、テーブル名を付けない
        $messages = $channel->topLevelMessages()
            ->notDeleted()
            ->with('user')
            ->withCount('replies')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE);

        return MessageResource::collection($messages);
    }
}
