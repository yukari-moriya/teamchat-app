<?php

namespace Database\Seeders;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 初期データの返信（docs/design/features.md の F-26、data.md の4章、D-30）。「開発」の5件。
 * 10:05（削除済み）の3件は mockup/thread.html のとおり。09:12 の2件はモックに無いので、ここで作った（D-27）。
 * 「リリース」は含めない。日時は固定で、何度流しても増えない。
 */
class ReplySeeder extends Seeder
{
    public function run(): void
    {
        // キーは、元のメッセージの投稿日時
        $threads = [
            '2026-08-17 09:12:00' => [
                ['takahashi@example.com', '2026-08-17 09:18:00', '分かりました。検索まわりの残りのタスクは、木曜のうちにまとめて共有します。'],
                ['sato@example.com', '2026-08-17 09:25:00', '私の分も、金曜の午前中には一覧にしておきます。'],
            ],
            '2026-08-17 10:05:00' => [
                ['sato@example.com', '2026-08-17 10:12:00', 'こちらの環境でも同じ症状が出ました。ログインだけ失敗して、他の画面は問題ありません。'],
                ['suzuki@example.com', '2026-08-17 10:48:00', '原因が分かりました。ステージングの設定ファイルの参照先が古いままでした。更新しておきます。'],
                ['takahashi@example.com', '2026-08-17 11:02:00', '更新を確認しました。ログインできています。ありがとうございます。'],
            ],
        ];

        $channel = Channel::where('name', '開発')->orderBy('id')->firstOrFail();
        $users = User::all()->keyBy('email');

        foreach ($threads as $originPostedAt => $rows) {
            $origin = $channel->topLevelMessages()->where('created_at', $originPostedAt)->orderBy('id')->firstOrFail();

            foreach ($rows as [$email, $postedAt, $body]) {
                $this->reply($origin, $users->get($email), $postedAt, $body);
            }
        }
    }

    private function reply(Message $origin, User $user, string $postedAt, string $body): void
    {
        // 同じ人・同じ日時の、返信でないメッセージがあるので、parent_id も込みで見分ける
        $key = ['parent_id' => $origin->id, 'user_id' => $user->id, 'created_at' => $postedAt];

        if (Message::where($key)->exists()) {
            return;
        }

        Message::forceCreate([
            ...$key,
            'channel_id' => $origin->channel_id,
            'body' => $body,
            'updated_at' => $postedAt,
        ]);
    }
}
