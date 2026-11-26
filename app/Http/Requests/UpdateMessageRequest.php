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

    /** エラーのときは、編集中の状態の、その行の位置へ戻す（D-02・D-32） */
    protected function getRedirectUrl(): string
    {
        $message = $this->route('message');

        return route('channels.show', ['channel' => $this->route('channel'), 'edit' => $message->id])
            .'#'.$message->anchor();
    }
}
