<?php

namespace App\Http\Requests;

/**
 * スレッドの返信欄から書く。エラーのときは、送った画面に戻る（Laravel の標準のまま）。
 */
class StoreReplyRequest extends MessageRequest
{
    protected function field(): string
    {
        return 'reply_body';
    }

    /** 返信には返信できない（R-08）。入力チェックより先に確かめて、404 にする（D-38） */
    protected function prepareForValidation(): void
    {
        abort_if($this->route('message')->isReply(), 404);

        parent::prepareForValidation();
    }
}
