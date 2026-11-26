<?php

namespace App\Http\Requests;

/**
 * 投稿欄から新しく書く。エラーのときは、送った画面に戻る（Laravel の標準のまま）。
 */
class StoreMessageRequest extends MessageRequest
{
    protected function field(): string
    {
        return 'body';
    }
}
