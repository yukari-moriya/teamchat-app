<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 公開APIが返すメッセージ（docs/design/permissions-api.md 3-3 の data[]）。返す項目は、ここに挙げたものだけ。
 * reply_count は、返信の件数を withCount('replies') で数えてから渡す。
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'author_name' => $this->user->name,
            'created_at' => $this->created_at->toIso8601String(),
            'is_edited' => $this->is_edited,
            'reply_count' => $this->replies_count,
        ];
    }
}
