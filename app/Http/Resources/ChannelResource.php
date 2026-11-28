<?php

namespace App\Http\Resources;

use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 公開APIが返すチャンネル（docs/design/permissions-api.md 3-2）。返す項目は、ここに挙げたものだけ。
 *
 * @mixin Channel
 */
class ChannelResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'creator_name' => $this->creator->name,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
