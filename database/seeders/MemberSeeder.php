<?php

namespace Database\Seeders;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 初期データの「採用プロジェクト」のメンバー 鈴木 花子（docs/design/data.md の4章、D-30）。
 * 作成者の行は ChannelSeeder が作る。追加日時は固定で、何度流しても増えない。
 */
class MemberSeeder extends Seeder
{
    public function run(): void
    {
        $creator = User::where('email', 'sato@example.com')->firstOrFail();
        $member = User::where('email', 'suzuki@example.com')->firstOrFail();
        $channel = Channel::where('name', '採用プロジェクト')->where('creator_id', $creator->id)->firstOrFail();
        $addedAt = '2026-08-03 09:35:00';

        $channel->members()->syncWithoutDetaching([$member->id => ['created_at' => $addedAt, 'updated_at' => $addedAt]]);
    }
}
