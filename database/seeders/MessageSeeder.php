<?php

namespace Database\Seeders;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 初期データのメッセージ（docs/design/features.md の F-26、data.md の4章、D-30）。返信は ReplySeeder で作る。
 * 「開発」の8件は mockup/channel-show.html、検索の見本の3件は mockup/search.html のとおり。
 * モックに無い本文は、ここで作った（D-27）。「リリース」を含むのは検索の見本の3件だけにする。
 * 日時は固定で、何度流しても増えない。
 */
class MessageSeeder extends Seeder
{
    private const EDITED = 'edited';

    private const DELETED = 'deleted';

    public function run(): void
    {
        $messages = [
            '全体連絡' => [
                ['suzuki@example.com', '2026-08-12 09:00:00', '今週の全体ミーティングは、木曜の16時からです。議題があれば前日までに教えてください。'],
                ['takahashi@example.com', '2026-08-13 12:30:00', '3階の複合機は、午後から点検が入ります。印刷は2階の機械を使ってください。'],
                ['sato@example.com', '2026-08-14 17:05:00', '9月リリースの社内向け案内文を、来週あたまに共有します。'],
            ],
            '開発' => [
                ['suzuki@example.com', '2026-08-17 09:12:00', 'おはようございます。9月リリースの日程を今週中に確定させたいです。金曜の夕方までに、残っているタスクを各自共有してもらえますか。'],
                ['takahashi@example.com', '2026-08-17 09:20:00', '承知しました。検索まわりの実装は木曜に終わる見込みです。', self::EDITED],
                ['sato@example.com', '2026-08-17 09:31:00', 'ありがとうございます。私はチャンネル管理画面のレビューを今日中に返します。'],
                ['takahashi@example.com', '2026-08-17 10:05:00', 'ステージング環境で、ログインに失敗します。手元で同じ症状が出る方はいますか。', self::DELETED],
                ['suzuki@example.com', '2026-08-17 10:48:00', 'ステージング環境でログインに失敗する件、原因が分かりました。設定ファイルの参照先が古いままでした。夕方までに直します。'],
                ['sato@example.com', '2026-08-17 11:15:00', '助かりました。手順書のほうも合わせて直しておきます。'],
                ['takahashi@example.com', '2026-08-17 13:40:00', '見積もりの件、来週の打ち合わせまでに数字を揃えておきます。必要な資料があれば教えてください。'],
                ['sato@example.com', '2026-08-17 14:32:00', 'ありがとうございます。前回と同じ様式で大丈夫です。こちらでも先週分の実績をまとめておきます。'],
            ],
            '雑談' => [
                ['takahashi@example.com', '2026-08-13 15:10:00', '駅前に新しいパン屋ができていました。'],
                ['suzuki@example.com', '2026-08-13 15:25:00', '気になっていました。おすすめがあれば教えてください。'],
            ],
            '採用プロジェクト' => [
                ['sato@example.com', '2026-08-12 10:40:00', '一次面談の候補者が2名になりました。来週で日程を組みたいです。'],
                ['suzuki@example.com', '2026-08-12 11:20:00', '面談の日程調整、リリース週は避けてもらえると助かります。'],
                ['sato@example.com', '2026-08-12 11:34:00', '分かりました。その週を外して候補を出します。'],
            ],
        ];

        $users = User::all()->keyBy('email');

        foreach ($messages as $channelName => $rows) {
            $channel = Channel::where('name', $channelName)->orderBy('id')->firstOrFail();

            foreach ($rows as $row) {
                [$email, $postedAt, $body] = $row;
                $this->post($channel, $users->get($email), $postedAt, $body, $row[3] ?? null);
            }
        }
    }

    private function post(Channel $channel, User $user, string $postedAt, string $body, ?string $state): void
    {
        $key = ['channel_id' => $channel->id, 'user_id' => $user->id, 'created_at' => $postedAt];

        if (Message::where($key)->whereNull('parent_id')->exists()) {
            return;
        }

        Message::forceCreate([
            ...$key,
            'body' => $body,
            'is_edited' => $state === self::EDITED,
            'deleted_at' => $state === self::DELETED ? $postedAt : null,
            'updated_at' => $postedAt,
        ]);
    }
}
