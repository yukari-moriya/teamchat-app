<?php

namespace Database\Seeders;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 初期データのチャンネル4つと、「採用プロジェクト」の作成者のメンバーの行（docs/design/data.md の4章、D-30）。
 * 作成日時は、一覧の並び（D-17）がこの順になる固定の値にする（D-27）。何度流しても増えない。
 */
class ChannelSeeder extends Seeder
{
    public function run(): void
    {
        $channels = [
            ['全体連絡', '全員に共有したいこと', false, 'suzuki@example.com', '2026-08-03 09:00:00'],
            ['開発', '開発に関する相談と報告', false, 'sato@example.com', '2026-08-03 09:10:00'],
            ['雑談', 'なんでもどうぞ', false, 'takahashi@example.com', '2026-08-03 09:20:00'],
            ['採用プロジェクト', '採用まわりの進行', true, 'sato@example.com', '2026-08-03 09:30:00'],
        ];

        foreach ($channels as [$name, $description, $isPrivate, $creatorEmail, $createdAt]) {
            $creator = User::where('email', $creatorEmail)->firstOrFail();
            $timestamps = ['created_at' => $createdAt, 'updated_at' => $createdAt];

            $channel = Channel::where('name', $name)->where('creator_id', $creator->id)->first()
                ?? Channel::forceCreate([
                    'name' => $name,
                    'description' => $description,
                    'is_private' => $isPrivate,
                    'creator_id' => $creator->id,
                    ...$timestamps,
                ]);

            if ($channel->is_private) {
                $channel->members()->syncWithoutDetaching([$creator->id => $timestamps]);
            }
        }
    }
}
