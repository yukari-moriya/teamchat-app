<?php

namespace App\Http\Requests;

/**
 * 編集欄から直す。
 */
class UpdateMessageRequest extends MessageRequest
{
    protected function field(): string
    {
        return 'edit_body';
    }

    /**
     * エラーのときは、編集中の状態の、その行の位置へ戻す（D-02・D-32）。
     * 開いていたスレッドは開いたままにする。thread の扱いは、操作が通ったときと同じ（D-37）。
     */
    protected function getRedirectUrl(): string
    {
        $thread = $this->route('channel')->threadOrigin($this->input('thread'));

        return $this->route('message')->url($thread?->id, editing: true);
    }
}
