<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * メンバー管理（実装単位 3）。観点は docs/design/acceptance.md の 2-3（TP-3-01〜14）と、2-8 の初期データの分。
 * TP-3-06・TP-3-07 の「検索に出る・出ない」→ 実装単位 6。
 */
class MemberTest extends TestCase
{
    use RefreshDatabase;

    private const ROW = '//div[@class="rows"]/div[@class="row"]';

    private User $me;

    private User $other;

    private User $outsider;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['name' => '佐藤 太郎', 'email' => 'sato@example.com']);
        $this->other = User::factory()->create(['name' => '鈴木 花子', 'email' => 'suzuki@example.com']);
        $this->outsider = User::factory()->create(['name' => '高橋 健', 'email' => 'takahashi@example.com']);
        $this->channel = Channel::factory()->private()->create([
            'name' => '採用プロジェクト',
            'description' => '採用まわりの進行',
            'creator_id' => $this->me->id,
        ]);
    }

    private function url(?Channel $channel = null, int|string|null $userId = null): string
    {
        $channel ??= $this->channel;

        return "/channels/{$channel->id}/members".($userId === null ? '' : "/{$userId}");
    }

    private function openMembers(?User $actor = null, ?Channel $channel = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->me)->get($this->url($channel));
    }

    private function addMember(mixed $input, ?User $actor = null, ?Channel $channel = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->me)->from($this->url($channel))
            ->post($this->url($channel), ['member' => $input]);
    }

    private function removeMember(User|int|string $member, ?User $actor = null, ?Channel $channel = null): TestResponse
    {
        $id = $member instanceof User ? $member->id : $member;

        return $this->actingAs($actor ?? $this->me)->delete($this->url($channel, $id));
    }

    /** @return list<array{int, int}> channel_user のすべての行（チャンネルと利用者の ID） */
    private function memberRows(): array
    {
        return DB::table('channel_user')->orderBy('channel_id')->orderBy('user_id')->get()
            ->map(fn (object $row) => [(int) $row->channel_id, (int) $row->user_id])->all();
    }

    private function xpath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent());
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    /** @return list<string> */
    private function texts(DOMXPath $xpath, string $query, ?DOMElement $context = null): array
    {
        $texts = [];
        foreach ($xpath->query($query, $context) as $node) {
            $text = trim($node->textContent);
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /** @return list<string> */
    private function sidebarNames(TestResponse $response): array
    {
        return $this->texts($this->xpath($response), '//nav[@class="sidebar"]//span[@class="nm"]');
    }

    /** @return list<string> */
    private function memberNames(TestResponse $response): array
    {
        // 行の題は「表示名の文字・作成者のバッジ」なので、直下の文字だけを取る
        return $this->texts($this->xpath($response), self::ROW.'//div[@class="row__title"]/text()');
    }

    // TP-3-01
    public function test_shows_the_members_with_their_items(): void
    {
        $this->channel->members()->attach($this->other);

        $response = $this->openMembers()->assertOk();
        $xpath = $this->xpath($response);

        $this->assertSame(['佐藤 太郎', '鈴木 花子'], $this->memberNames($response));
        $this->assertSame(
            ['sato@example.com', 'suzuki@example.com'],
            $this->texts($xpath, self::ROW.'//div[@class="row__sub"]'),
        );
        $this->assertSame(['佐藤', '鈴木'], $this->texts($xpath, self::ROW.'/span[contains(@class, "avatar")]'));
        $this->assertSame(['作成者'], $this->texts($xpath, '('.self::ROW.')[1]//span[@class="badge badge--owner"]'));
        $this->assertSame(1, $xpath->query('//span[@class="badge badge--owner"]')->length);
        $this->assertContains('メンバー　2人', $this->texts($xpath, '//main//h2'));
        $this->assertContains('メンバーを追加する', $this->texts($xpath, '//main//h2'));

        $title = $xpath->query('//main//h1')->item(0);
        $this->assertSame(1, $xpath->query('./span[@class="sig"]/*[local-name()="svg"][@aria-label="鍵"]', $title)->length);
        $this->assertSame(['採用プロジェクト'], $this->texts($xpath, './text()', $title));
        $this->assertSame(['プライベート'], $this->texts($xpath, './span[@class="badge badge--private"]', $title));
        $this->assertSame(
            ['採用まわりの進行　·　追加された人だけが読み書きできます。'],
            $this->texts($xpath, '//main//p[@class="t-caption"]'),
        );
        $this->assertSame(
            route('channels.show', $this->channel),
            $xpath->query('//main//a[normalize-space(.)="チャンネルを開く"]')->item(0)->getAttribute('href'),
        );

        $input = $xpath->query('//input[@name="member"]')->item(0);
        $this->assertSame(['表示名またはメールアドレスで探す', ''], [$input->getAttribute('placeholder'), $input->getAttribute('value')]);
        $this->assertSame(['追加'], $this->texts($xpath, '//input[@name="member"]/following-sibling::button'));
        $this->assertSame(0, $xpath->query('//span[@class="err"]')->length);
        $this->assertSame(0, $xpath->query('//script')->length);

        // サイドバーでは、開いているチャンネルが選択中になる
        $this->assertSame(['採用プロジェクト'], $this->texts($xpath, '//a[contains(@class, "is-selected")]/span[@class="nm"]'));
    }

    // TP-3-01
    public function test_shows_only_the_guide_when_the_description_is_empty(): void
    {
        $this->channel->update(['description' => null]);

        $xpath = $this->xpath($this->openMembers());

        $this->assertSame(['追加された人だけが読み書きできます。'], $this->texts($xpath, '//main//p[@class="t-caption"]'));
    }

    // TP-3-01（D-34）
    public function test_members_are_ordered_by_creator_then_added_time_then_user_id(): void
    {
        $smallId = User::factory()->create(['name' => '同着で ID が小さい']);
        $largeId = User::factory()->create(['name' => '同着で ID が大きい']);
        $first = User::factory()->create(['name' => '先に追加']);
        // 作成者は、ID がいちばん大きく、追加日時もいちばん遅くしても、先頭になる
        $creator = User::factory()->create(['name' => '作成者の人']);
        $channel = Channel::factory()->create(['is_private' => true, 'creator_id' => $creator->id]);
        // 同じ日時の2人は、ID の大きい人から追加する
        foreach ([
            [$largeId, '2026-08-02 09:00:00'],
            [$smallId, '2026-08-02 09:00:00'],
            [$first, '2026-08-01 09:00:00'],
            [$creator, '2026-08-03 09:00:00'],
        ] as [$user, $addedAt]) {
            $channel->members()->attach($user, ['created_at' => $addedAt, 'updated_at' => $addedAt]);
        }

        $response = $this->openMembers($creator, $channel)->assertOk();

        $this->assertSame(['作成者の人', '先に追加', '同着で ID が小さい', '同着で ID が大きい'], $this->memberNames($response));
    }

    // TP-3-01（D-34）: 上のテストは、利用者の ID の指定が無くても通る（DB がたまたま ID の順で返す）。
    // そのため、画面が実際に投げる問い合わせの、並びの指定も確かめる
    public function test_the_member_list_query_breaks_ties_by_user_id(): void
    {
        DB::enableQueryLog();
        $this->openMembers()->assertOk();
        DB::disableQueryLog();

        $this->assertNotEmpty(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            fn (string $sql) => str_ends_with($sql, 'order by users.id = ? desc, `channel_user`.`created_at` asc, `users`.`id` asc'),
        ));
    }

    // TP-3-01: 行が増えても、問い合わせは増えない
    public function test_the_number_of_queries_does_not_grow_with_members(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->openMembers()->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->channel->members()->attach($this->other);
        $withTwo = $count();

        $this->channel->members()->attach(User::factory()->count(5)->create());
        $withSeven = $count();

        $this->assertSame($withTwo, $withSeven);
    }

    // TP-3-02
    public function test_can_add_by_name_or_email_ignoring_case_and_width(): void
    {
        $target = User::factory()->create(['name' => 'Taro山田ガイド', 'email' => 'taro.yamada@example.com']);

        foreach ([
            'Taro山田ガイド',
            'TARO山田ガイド',
            'ｔａｒｏ山田ｶﾞｲﾄﾞ',
            "　Taro山田ガイド \n",
            'taro.yamada@example.com',
            'TARO.Yamada@Example.COM',
            'ｔａｒｏ．ｙａｍａｄａ＠ｅｘａｍｐｌｅ．ｃｏｍ',
        ] as $input) {
            $this->addMember($input)->assertRedirect($this->url())->assertSessionHasNoErrors();

            $this->assertDatabaseHas('channel_user', ['channel_id' => $this->channel->id, 'user_id' => $target->id]);
            $this->assertDatabaseCount('channel_user', 2);
            $this->channel->members()->detach($target);
        }

        // 全角の空白は、半角の空白と同じ
        $this->addMember('高橋　健')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('channel_user', ['channel_id' => $this->channel->id, 'user_id' => $this->outsider->id]);
        $this->assertSame(['佐藤 太郎', '高橋 健'], $this->memberNames($this->openMembers()));
    }

    // TP-3-02: ひらがなとカタカナ、濁点の有無は区別する
    public function test_kana_and_voiced_marks_are_distinguished(): void
    {
        User::factory()->create(['name' => 'Taro山田ガイド']);

        foreach (['Taro山田がいど', 'Taro山田カイト'] as $input) {
            $this->addMember($input)->assertSessionHasErrors(['member' => '一致する人が見つかりません']);
        }

        $this->assertDatabaseCount('channel_user', 1);
    }

    // TP-3-03
    public function test_a_partial_match_adds_nobody(): void
    {
        $inputs = [
            '高橋', '健', '高橋健', '高橋 健さん', 'takahashi', 'takahashi@example', 'akahashi@example.com',
            'takahashi@example.com.jp', '@', '%', '高橋 %', 'takahashi@%', '高橋 _',
        ];

        foreach ($inputs as $input) {
            $this->addMember($input)
                ->assertRedirect($this->url())
                ->assertSessionHasErrors(['member' => '一致する人が見つかりません']);
        }

        $this->assertDatabaseCount('channel_user', 1);
    }

    // TP-3-03: エラーは欄の下に1つ出し、入力した値は残す
    public function test_the_error_is_shown_under_the_field_and_the_input_is_kept(): void
    {
        $this->addMember('高橋"<健>')->assertRedirect($this->url());

        $xpath = $this->xpath($this->get($this->url()));

        $this->assertSame(
            ['一致する人が見つかりません'],
            $this->texts($xpath, '//div[contains(@class, "field") and contains(@class, "is-error")]/span[@class="err"]'),
        );
        $this->assertSame(1, $xpath->query('//span[@class="err"]')->length);
        $this->assertSame('高橋"<健>', $xpath->query('//input[@name="member"]')->item(0)->getAttribute('value'));
    }

    // TP-3-04
    public function test_input_is_required(): void
    {
        $message = '表示名またはメールアドレスを入力してください';

        foreach (['', '   ', '　　', " \n ", null, [], ['takahashi@example.com']] as $input) {
            $this->addMember($input)
                ->assertRedirect($this->url())
                ->assertSessionHasErrors(['member' => $message]);
        }
        // 項目そのものが無くても同じ
        $this->actingAs($this->me)->from($this->url())->post($this->url())
            ->assertSessionHasErrors(['member' => $message]);

        $this->get($this->url())->assertOk()->assertSee($message);
        $this->assertDatabaseCount('channel_user', 1);
    }

    // TP-3-04: 配列は画面からは送れない。直接送られても、戻った先の画面が開けて、エラーが出る
    public function test_the_page_opens_after_an_array_is_sent(): void
    {
        $this->addMember(['takahashi@example.com'])->assertRedirect($this->url());

        $response = $this->get($this->url())->assertOk()->assertSee('表示名またはメールアドレスを入力してください');

        $this->assertSame('', $this->xpath($response)->query('//input[@name="member"]')->item(0)->getAttribute('value'));
    }

    // TP-3-05
    public function test_an_existing_member_cannot_be_added_again(): void
    {
        $this->channel->members()->attach($this->other);

        foreach (['鈴木 花子', 'SUZUKI@example.com', '佐藤 太郎', 'sato@example.com'] as $input) {
            $this->addMember($input)
                ->assertRedirect($this->url())
                ->assertSessionHasErrors(['member' => 'その人はすでにメンバーです']);
        }

        $this->assertDatabaseCount('channel_user', 2);
    }

    // TP-3-05: ほかのチャンネルのメンバーであることは、関係ない
    public function test_a_member_of_another_channel_can_be_added(): void
    {
        $another = Channel::factory()->private()->create(['creator_id' => $this->outsider->id]);

        $this->addMember('高橋 健')->assertSessionHasNoErrors();

        $this->assertSame([
            [$this->channel->id, $this->me->id],
            [$this->channel->id, $this->outsider->id],
            [$another->id, $this->outsider->id],
        ], $this->memberRows());
    }

    // TP-3-06
    public function test_an_added_member_can_read_earlier_messages_and_post(): void
    {
        Message::factory()->create([
            'channel_id' => $this->channel->id,
            'user_id' => $this->me->id,
            'body' => '追加される前のメッセージ',
            'created_at' => now()->subDay(),
        ]);
        $this->actingAs($this->outsider)->get("/channels/{$this->channel->id}")->assertNotFound();
        $this->assertSame([], $this->sidebarNames($this->get('/channels')));

        $this->addMember('takahashi@example.com')->assertRedirect($this->url());

        $response = $this->actingAs($this->outsider)->get("/channels/{$this->channel->id}")
            ->assertOk()
            ->assertSee('追加される前のメッセージ');
        $this->assertSame(['採用プロジェクト'], $this->sidebarNames($response));

        $this->post("/channels/{$this->channel->id}/messages", ['body' => '追加されたあとの書き込み'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $this->assertDatabaseHas('messages', [
            'channel_id' => $this->channel->id,
            'user_id' => $this->outsider->id,
            'body' => '追加されたあとの書き込み',
        ]);
    }

    // TP-3-07
    public function test_a_removed_member_loses_access_and_their_messages_remain(): void
    {
        $this->channel->members()->attach([$this->other->id, $this->outsider->id]);
        $message = Message::factory()->create([
            'channel_id' => $this->channel->id,
            'user_id' => $this->other->id,
            'body' => '外される人のメッセージ',
        ]);
        $this->actingAs($this->other)->get("/channels/{$this->channel->id}")->assertOk();

        $this->removeMember($this->other)->assertRedirect($this->url());

        // 外すのは、その人の行だけ
        $this->assertSame(
            [[$this->channel->id, $this->me->id], [$this->channel->id, $this->outsider->id]],
            $this->memberRows(),
        );
        $this->assertSame(['佐藤 太郎', '高橋 健'], $this->memberNames($this->openMembers()));

        $this->actingAs($this->other)->get("/channels/{$this->channel->id}")->assertNotFound();
        $response = $this->get('/channels')->assertOk()->assertDontSee('採用プロジェクト');
        $this->assertSame([], $this->sidebarNames($response));

        $this->assertNull($message->refresh()->deleted_at);
        $this->actingAs($this->me)->get("/channels/{$this->channel->id}")->assertSee('外される人のメッセージ');
    }

    // TP-3-08
    public function test_a_removed_member_cannot_edit_or_delete_their_own_messages(): void
    {
        $this->channel->members()->attach($this->other);
        $message = Message::factory()->create([
            'channel_id' => $this->channel->id,
            'user_id' => $this->other->id,
            'body' => '元の本文',
        ]);

        $this->removeMember($this->other)->assertRedirect($this->url());

        $url = "/channels/{$this->channel->id}/messages/{$message->id}";
        $this->actingAs($this->other)->put($url, ['edit_body' => '書き換え'])->assertNotFound();
        $this->delete($url)->assertNotFound();

        $message->refresh();
        $this->assertSame(['元の本文', false, null], [$message->body, $message->is_edited, $message->deleted_at]);
    }

    // TP-3-09
    public function test_only_the_creator_row_cannot_be_removed(): void
    {
        $this->channel->members()->attach($this->other);

        $xpath = $this->xpath($this->openMembers());

        $creatorRow = $xpath->query('('.self::ROW.')[1]')->item(0);
        $this->assertSame(['作成者は外せません'], $this->texts($xpath, './div[@class="row__acts"]/span', $creatorRow));
        $this->assertSame(['外す'], $this->texts($xpath, './div[@class="row__acts"]/button[@disabled and @type="button"]', $creatorRow));
        $this->assertSame(0, $xpath->query('.//form', $creatorRow)->length);

        $memberRow = $xpath->query('('.self::ROW.')[2]')->item(0);
        $this->assertSame(['外す'], $this->texts($xpath, './/form/button[@type="submit" and not(@disabled)]', $memberRow));
        $this->assertSame([], $this->texts($xpath, './div[@class="row__acts"]/span', $memberRow));
        $form = $xpath->query('.//form', $memberRow)->item(0);
        $this->assertSame(
            route('channels.members.destroy', [$this->channel, $this->other]),
            $form->getAttribute('action'),
        );
        $this->assertSame(1, $xpath->query('./input[@name="_method" and @value="DELETE"]', $form)->length);
        $this->assertSame(1, $xpath->query('./input[@name="_token"]', $form)->length);
    }

    // TP-3-09
    public function test_removing_the_creator_directly_is_forbidden(): void
    {
        $this->removeMember($this->me)->assertForbidden();

        $this->assertSame([[$this->channel->id, $this->me->id]], $this->memberRows());
    }

    // TP-3-10
    public function test_a_member_who_is_not_the_creator_gets_403(): void
    {
        $this->channel->members()->attach([$this->other->id, User::factory()->create()->id]);
        $anotherMember = $this->channel->members()->whereKeyNot([$this->me->id, $this->other->id])->sole();
        $rows = $this->memberRows();

        $this->openMembers($this->other)->assertForbidden()->assertDontSee('sato@example.com');
        // 入力が誤っていても、先に権限を見る
        foreach (['takahashi@example.com', '', 'いない人'] as $input) {
            $this->addMember($input, $this->other)->assertForbidden();
        }
        // ほかの人・自分・作成者・メンバーでない人のどれを外そうとしても同じ。違うと、誰がメンバーかが分かる（D-35）
        foreach ([$anotherMember, $this->other, $this->me, $this->outsider] as $target) {
            $this->removeMember($target, $this->other)->assertForbidden();
        }

        $this->assertSame($rows, $this->memberRows());
    }

    // TP-3-10
    public function test_a_non_member_gets_404(): void
    {
        $this->channel->members()->attach($this->other);
        $rows = $this->memberRows();

        $this->openMembers($this->outsider)->assertNotFound()->assertDontSee('採用プロジェクト');
        foreach (['takahashi@example.com', ''] as $input) {
            $this->addMember($input, $this->outsider)->assertNotFound();
        }
        foreach ([$this->other, $this->me, $this->outsider] as $target) {
            $this->removeMember($target, $this->outsider)->assertNotFound();
        }

        $this->assertSame($rows, $this->memberRows());
    }

    // TP-3-11
    public function test_public_channels_have_no_member_management(): void
    {
        $public = Channel::factory()->create(['creator_id' => $this->me->id]);
        $rows = $this->memberRows();

        foreach ([$this->me, $this->other] as $actor) {
            $this->openMembers($actor, $public)->assertNotFound();
            foreach (['takahashi@example.com', ''] as $input) {
                $this->addMember($input, $actor, $public)->assertNotFound();
            }
            $this->removeMember($this->me, $actor, $public)->assertNotFound();
            $this->removeMember($this->outsider, $actor, $public)->assertNotFound();
        }

        $this->assertSame($rows, $this->memberRows());
    }

    // TP-3-11（D-23）: 公開チャンネルに行があっても（本来は作らない）、外す操作は権限の判定で 404
    public function test_removal_on_a_public_channel_is_404_even_with_a_member_row(): void
    {
        $public = Channel::factory()->create(['creator_id' => $this->me->id]);
        $public->members()->attach([$this->me->id, $this->outsider->id]);
        $rows = $this->memberRows();

        foreach ([$this->me, $this->other] as $actor) {
            $this->removeMember($this->me, $actor, $public)->assertNotFound();
            $this->removeMember($this->outsider, $actor, $public)->assertNotFound();
        }

        $this->assertSame($rows, $this->memberRows());
    }

    // TP-3-12: 作成者が、メンバーでない利用者を指したとき。存在しない ID も含む
    public function test_removing_a_user_who_is_not_a_member_is_404(): void
    {
        $this->channel->members()->attach($this->other);
        // ほかのチャンネルのメンバーでも、このチャンネルのメンバーでなければ 404
        Channel::factory()->private()->create(['creator_id' => $this->outsider->id]);
        $rows = $this->memberRows();

        // 数字で始まる別の文字列が、その ID の利用者にならない
        foreach ([$this->outsider->id, 999999, "{$this->other->id}abc", "{$this->me->id}abc", 'abc'] as $userId) {
            $this->removeMember($userId)->assertNotFound();
        }

        $this->assertSame($rows, $this->memberRows());
    }

    // TP-3-12（D-35）: 存在しない利用者の ID は、作成者でない人が送っても 404
    public function test_a_missing_user_id_is_404_for_a_member_who_is_not_the_creator(): void
    {
        $this->channel->members()->attach($this->other);
        $rows = $this->memberRows();

        foreach ([999999, "{$this->me->id}abc", 'abc'] as $userId) {
            $this->removeMember($userId, $this->other)->assertNotFound();
        }

        $this->assertSame($rows, $this->memberRows());
    }

    // TP-3-13
    public function test_a_nearly_simultaneous_second_addition_fails_with_500(): void
    {
        $inserted = false;
        // 「すでにメンバーか」を調べた直後に、もう一方の送信が同じ行を書いたことにする
        DB::listen(function ($query) use (&$inserted) {
            if (! $inserted && str_starts_with($query->sql, 'select exists(select * from `users` inner join `channel_user`')) {
                $inserted = true;
                DB::table('channel_user')->insert([
                    'channel_id' => $this->channel->id,
                    'user_id' => $this->outsider->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $response = $this->addMember('高橋 健')->assertStatus(500);

        $this->assertTrue($inserted);
        $this->assertInstanceOf(UniqueConstraintViolationException::class, $response->exception);
        $this->assertSame(
            [[$this->channel->id, $this->me->id], [$this->channel->id, $this->outsider->id]],
            $this->memberRows(),
        );
    }

    // TP-3-14（D-24）
    public function test_input_with_an_at_sign_is_compared_only_with_email(): void
    {
        // 表示名に「@」は登録できないが、比べる列を確かめるために、直接作る
        User::factory()->create(['name' => 'name@only', 'email' => 'someone@example.com']);

        foreach (['name@only', 'name＠only'] as $input) {
            $this->addMember($input)->assertSessionHasErrors(['member' => '一致する人が見つかりません']);
        }
        $this->assertDatabaseCount('channel_user', 1);

        $this->addMember('someone＠example.com')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('channel_user', 2);
    }

    // TP-3-14（D-24）
    public function test_input_without_an_at_sign_is_compared_only_with_name(): void
    {
        // メールアドレスは必ず「@」を持つが、比べる列を確かめるために、直接作る
        User::factory()->create(['name' => '別の表示名', 'email' => 'mail-only']);

        $this->addMember('mail-only')->assertSessionHasErrors(['member' => '一致する人が見つかりません']);
        $this->assertDatabaseCount('channel_user', 1);

        $this->addMember('別の表示名')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('channel_user', 2);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->channel->members()->attach($this->other);
        $rows = $this->memberRows();

        $this->get($this->url())->assertRedirect('/login');
        $this->post($this->url(), ['member' => 'takahashi@example.com'])->assertRedirect('/login');
        foreach ([$this->other->id, $this->me->id, $this->outsider->id] as $userId) {
            $this->delete($this->url(userId: $userId))->assertRedirect('/login');
        }

        $this->assertSame($rows, $this->memberRows());
    }

    // 初期データ（TP-8-01 のメンバーの分。D-30）。何度流しても増えない
    public function test_seeded_members(): void
    {
        $this->channel->delete();
        User::query()->delete();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('channel_user', 2);
        $channel = Channel::where('name', '採用プロジェクト')->sole();
        $sato = User::where('email', 'sato@example.com')->sole();
        $suzuki = User::where('email', 'suzuki@example.com')->sole();
        $this->assertDatabaseHas('channel_user', [
            'channel_id' => $channel->id,
            'user_id' => $suzuki->id,
            'created_at' => '2026-08-03 09:35:00',
        ]);

        $response = $this->openMembers($sato, $channel)->assertOk()->assertSee('メンバー　2人');
        $this->assertSame(['佐藤 太郎', '鈴木 花子'], $this->memberNames($response));

        $this->openMembers($suzuki, $channel)->assertForbidden();
        $this->get('/channels')->assertDontSee($this->url($channel));
    }
}
