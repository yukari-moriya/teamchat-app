<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * チャンネル（実装単位 2）。観点は docs/design/acceptance.md の 2-2（TP-2-01〜19）。
 */
class ChannelTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['name' => '佐藤 太郎']);
        $this->other = User::factory()->create(['name' => '鈴木 花子']);
    }

    /** @param array<string, mixed> $attributes */
    private function publicChannel(User $creator, array $attributes = []): Channel
    {
        return Channel::factory()->create(['creator_id' => $creator->id, ...$attributes]);
    }

    /** @param array<string, mixed> $attributes */
    private function privateChannel(User $creator, array $attributes = []): Channel
    {
        return Channel::factory()->private()->create(['creator_id' => $creator->id, ...$attributes]);
    }

    private function message(Channel $channel, ?Message $parent = null, bool $deleted = false): Message
    {
        return Message::forceCreate([
            'channel_id' => $channel->id,
            'user_id' => $this->other->id,
            'parent_id' => $parent?->id,
            'body' => '本文',
            'body_normalized' => '本文',
            'deleted_at' => $deleted ? now() : null,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createChannel(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->me)->from('/channels/create')->post('/channels', [
            'name' => '新しいチャンネル',
            'description' => '',
            'visibility' => 'public',
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $input */
    private function updateChannel(Channel $channel, array $input): TestResponse
    {
        return $this->actingAs($this->me)->from("/channels/{$channel->id}/edit")->put("/channels/{$channel->id}", $input);
    }

    private function deleteChannel(Channel $channel, string $confirmName): TestResponse
    {
        return $this->actingAs($this->me)->delete("/channels/{$channel->id}", ['confirm_name' => $confirmName]);
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
    private function listNames(TestResponse $response): array
    {
        // 行の題は「記号の span・チャンネル名の文字・バッジの span」なので、直下の文字だけを取る
        return $this->texts($this->xpath($response), '//div[@class="row__title"]/text()');
    }

    /** @return array<string, list<string>> チャンネル名 => その行の操作の文言 */
    private function listActions(TestResponse $response): array
    {
        $xpath = $this->xpath($response);
        $actions = [];
        foreach ($xpath->query('//div[@class="row"]') as $row) {
            $name = $this->texts($xpath, './/div[@class="row__title"]/text()', $row)[0];
            $actions[$name] = $this->texts($xpath, './/div[@class="row__acts"]/a', $row);
        }

        return $actions;
    }

    // TP-2-01
    public function test_sidebar_and_list_show_visible_channels_from_oldest(): void
    {
        $this->publicChannel($this->other, ['name' => '3番目', 'created_at' => '2026-08-03 09:00:00']);
        $this->publicChannel($this->me, ['name' => '1番目', 'created_at' => '2026-08-01 09:00:00']);
        $this->privateChannel($this->me, ['name' => '2番目', 'created_at' => '2026-08-02 09:00:00']);
        // 同じ日時なら、ID の小さい順
        $this->publicChannel($this->other, ['name' => '4番目', 'created_at' => '2026-08-04 09:00:00']);
        $this->publicChannel($this->other, ['name' => '5番目', 'created_at' => '2026-08-04 09:00:00']);

        $response = $this->actingAs($this->me)->get('/channels')->assertOk();

        $expected = ['1番目', '2番目', '3番目', '4番目', '5番目'];
        $this->assertSame($expected, $this->sidebarNames($response));
        $this->assertSame($expected, $this->listNames($response));
    }

    // TP-2-01: プライベートは、作った人でなくてもメンバーなら並ぶ
    public function test_private_channel_is_listed_for_a_member(): void
    {
        $channel = $this->privateChannel($this->other, ['name' => '呼ばれた部屋']);
        $channel->members()->attach($this->me);

        $response = $this->actingAs($this->me)->get('/channels');

        $this->assertSame(['呼ばれた部屋'], $this->sidebarNames($response));
        $this->assertSame(['呼ばれた部屋'], $this->listNames($response));
        $this->get("/channels/{$channel->id}")->assertOk();
    }

    // TP-2-02
    public function test_private_channel_is_hidden_from_non_members(): void
    {
        $this->publicChannel($this->other, ['name' => '公開の部屋']);
        $this->privateChannel($this->other, ['name' => '秘密の部屋', 'description' => '秘密の説明']);

        $response = $this->actingAs($this->me)->get('/channels');

        $response->assertDontSee('秘密の部屋')->assertDontSee('秘密の説明');
        $this->assertSame(['公開の部屋'], $this->sidebarNames($response));
        $this->get('/channels/create')->assertOk()->assertDontSee('秘密の部屋');
    }

    // TP-2-03
    public function test_list_actions_are_shown_only_to_the_creator(): void
    {
        $this->publicChannel($this->me, ['name' => '自分の公開']);
        $mine = $this->privateChannel($this->me, ['name' => '自分の非公開']);
        $this->publicChannel($this->other, ['name' => '他人の公開']);
        $theirs = $this->privateChannel($this->other, ['name' => '他人の非公開']);
        $theirs->members()->attach($this->me);

        $actions = $this->listActions($this->actingAs($this->me)->get('/channels'));

        $this->assertSame(['編集', '開く'], $actions['自分の公開']);
        $this->assertSame(['メンバー', '編集', '開く'], $actions['自分の非公開']);
        $this->assertSame(['開く'], $actions['他人の公開']);
        $this->assertSame(['開く'], $actions['他人の非公開']);
        $this->get('/channels')->assertSee("/channels/{$mine->id}/members");
    }

    // TP-2-03
    public function test_header_actions_are_shown_only_to_the_creator(): void
    {
        $mine = $this->publicChannel($this->me, ['description' => '説明の文']);
        $theirs = $this->publicChannel($this->other);

        $query = '//div[@class="ch-head__acts"]/a';

        $response = $this->actingAs($this->me)->get("/channels/{$mine->id}")->assertSee('説明の文')->assertSee('公開');
        $this->assertSame(['編集', '削除'], $this->texts($this->xpath($response), $query));
        $response->assertSee(route('channels.edit', ['channel' => $mine, 'confirm_delete' => 1]));

        $response = $this->get("/channels/{$theirs->id}");
        $this->assertSame([], $this->texts($this->xpath($response), $query));
    }

    // TP-2-04
    public function test_sidebar_shows_signs_and_the_selected_channel(): void
    {
        $public = $this->publicChannel($this->me, ['name' => '公開の部屋', 'created_at' => '2026-08-01 09:00:00']);
        $this->privateChannel($this->me, ['name' => '非公開の部屋', 'created_at' => '2026-08-02 09:00:00']);

        $xpath = $this->xpath($this->actingAs($this->me)->get('/channels'));
        $items = '//nav[@class="sidebar"]/a';
        $this->assertSame('#', trim($xpath->query("({$items})[1]/span[@class='sig']")->item(0)->textContent));
        $this->assertSame(1, $xpath->query("({$items})[2]/span[@class='sig']/*[local-name()='svg'][@aria-label='鍵']")->length);
        $this->assertSame(0, $xpath->query('//a[contains(@class, "is-selected")]')->length);

        foreach (["/channels/{$public->id}", "/channels/{$public->id}/edit"] as $url) {
            $xpath = $this->xpath($this->get($url));
            $this->assertSame(['公開の部屋'], $this->texts($xpath, '//a[contains(@class, "is-selected")]/span[@class="nm"]'), $url);
        }
    }

    // TP-2-05
    public function test_creating_a_public_channel_does_not_add_a_member_row(): void
    {
        $response = $this->createChannel(['name' => '公開で作る', 'description' => '説明', 'visibility' => 'public']);

        $channel = Channel::sole();
        $response->assertRedirect("/channels/{$channel->id}");
        $this->assertSame(['公開で作る', '説明', false, $this->me->id], [$channel->name, $channel->description, $channel->is_private, $channel->creator_id]);
        $this->assertDatabaseCount('channel_user', 0);
    }

    // TP-2-05
    public function test_creating_a_private_channel_adds_the_creator_as_a_member(): void
    {
        $response = $this->createChannel(['visibility' => 'private']);

        $channel = Channel::sole();
        $response->assertRedirect("/channels/{$channel->id}");
        $this->assertTrue($channel->is_private);
        $this->assertDatabaseCount('channel_user', 1);
        $this->assertDatabaseHas('channel_user', ['channel_id' => $channel->id, 'user_id' => $this->me->id]);
        $this->get("/channels/{$channel->id}")->assertOk();
    }

    // TP-2-06
    public function test_nothing_is_saved_when_creation_fails_halfway(): void
    {
        $failed = false;
        // channel_user に書いた直後に失敗させる
        DB::listen(function ($query) use (&$failed) {
            if (str_starts_with($query->sql, 'insert into `channel_user`')) {
                $failed = true;
                throw new RuntimeException('途中で失敗');
            }
        });

        $this->createChannel(['visibility' => 'private'])->assertStatus(500);

        $this->assertTrue($failed);
        $this->assertDatabaseCount('channels', 0);
        $this->assertDatabaseCount('channel_user', 0);
    }

    // TP-2-07
    public function test_name_is_required(): void
    {
        foreach (['', '   ', '　　', " \n "] as $name) {
            $this->createChannel(['name' => $name, 'description' => '残る説明'])
                ->assertRedirect('/channels/create')
                ->assertSessionHasErrors(['name' => 'チャンネル名を入力してください']);
        }

        $this->get('/channels/create')->assertSee('チャンネル名を入力してください')->assertSee('残る説明');
        $this->assertDatabaseCount('channels', 0);
    }

    // TP-2-07
    public function test_empty_description_is_saved_as_null(): void
    {
        $this->createChannel(['description' => '　 '])->assertSessionHasNoErrors();

        $this->assertNull(Channel::sole()->description);
    }

    // TP-2-08
    public function test_name_and_description_length_limits(): void
    {
        $this->createChannel(['name' => str_repeat('あ', 51)])
            ->assertSessionHasErrors(['name' => 'チャンネル名は50文字以内で入力してください']);
        $this->createChannel(['description' => str_repeat('あ', 201)])
            ->assertSessionHasErrors(['description' => '説明は200文字以内で入力してください']);
        $this->assertDatabaseCount('channels', 0);

        $this->createChannel(['name' => str_repeat('あ', 50), 'description' => str_repeat('あ', 200)])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('channels', 1);
    }

    // TP-2-08: ブラウザが送る CRLF の改行は、1文字に数える
    public function test_a_line_break_counts_as_one_character(): void
    {
        $this->createChannel(['description' => str_repeat('あ', 100)."\r\n".str_repeat('い', 99)])
            ->assertSessionHasNoErrors();

        $this->assertSame(200, mb_strlen(Channel::sole()->description));
    }

    // TP-2-09
    public function test_duplicate_name_ignores_case_and_width(): void
    {
        $this->publicChannel($this->other, ['name' => 'Dev開発ガイド1']);
        $this->privateChannel($this->me, ['name' => '自分の非公開']);

        foreach (['Dev開発ガイド1', 'DEV開発ガイド1', 'ｄｅｖ開発ｶﾞｲﾄﾞ１', '自分の非公開'] as $name) {
            $this->createChannel(['name' => $name])
                ->assertSessionHasErrors(['name' => 'そのチャンネル名はすでに使われています']);
        }
        $this->assertDatabaseCount('channels', 2);

        // ひらがなとカタカナ、濁点の有無は区別する
        foreach (['Dev開発がいど1', 'Dev開発カイト1'] as $name) {
            $this->createChannel(['name' => $name])->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('channels', 4);
    }

    // TP-2-10
    public function test_name_of_an_invisible_private_channel_can_be_used(): void
    {
        $this->privateChannel($this->other, ['name' => '秘密の部屋']);

        $this->createChannel(['name' => '秘密の部屋'])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('channels', 2);
    }

    // TP-2-11
    public function test_visibility_must_be_public_or_private(): void
    {
        foreach (['', 'secret', 'Public', ['private']] as $visibility) {
            $this->createChannel(['visibility' => $visibility])
                ->assertSessionHasErrors(['visibility' => '公開範囲を選んでください']);
        }

        $this->assertDatabaseCount('channels', 0);
    }

    // TP-2-12
    public function test_creator_can_edit_name_and_description_but_not_visibility(): void
    {
        $channel = $this->publicChannel($this->me, ['name' => '直す前', 'description' => '前の説明']);

        $this->actingAs($this->me)->get("/channels/{$channel->id}/edit")
            ->assertOk()
            ->assertSee('公開チャンネルです。公開範囲はあとから変更できません。')
            ->assertDontSee('name="visibility"', false)
            ->assertSee('直す前')
            ->assertSee('前の説明');

        $this->updateChannel($channel, [
            'name' => '直したあと',
            'description' => '',
            'visibility' => 'private',
            'is_private' => '1',
            'creator_id' => $this->other->id,
        ])->assertRedirect("/channels/{$channel->id}");

        $channel->refresh();
        $this->assertSame(['直したあと', null, false, $this->me->id], [$channel->name, $channel->description, $channel->is_private, $channel->creator_id]);
        $this->assertDatabaseCount('channel_user', 0);
    }

    // TP-2-12: エラーのあとは入力した値が残る。消した説明が、元の値に戻らない
    public function test_edit_keeps_the_input_after_an_error(): void
    {
        $channel = $this->privateChannel($this->me, ['name' => '元の名前', 'description' => '元の説明']);

        $this->updateChannel($channel, ['name' => str_repeat('a', 51), 'description' => ''])
            ->assertRedirect("/channels/{$channel->id}/edit")
            ->assertSessionHasErrors(['name' => 'チャンネル名は50文字以内で入力してください']);

        $response = $this->get("/channels/{$channel->id}/edit")->assertDontSee('元の説明');
        $xpath = $this->xpath($response);
        $this->assertSame(str_repeat('a', 51), $xpath->query('//input[@id="name"]')->item(0)->getAttribute('value'));
        $this->assertSame('元の名前', $channel->refresh()->name);
    }

    // TP-2-12
    public function test_edit_page_of_a_private_channel_has_its_own_note(): void
    {
        $channel = $this->privateChannel($this->me);

        $this->actingAs($this->me)->get("/channels/{$channel->id}/edit")
            ->assertSee('プライベートチャンネルです。公開範囲はあとから変更できません。');
    }

    // TP-2-13
    public function test_duplicate_check_on_edit_excludes_itself(): void
    {
        $channel = $this->publicChannel($this->me, ['name' => 'Release']);
        $this->publicChannel($this->other, ['name' => 'ほかの部屋']);

        $this->updateChannel($channel, ['name' => 'Release', 'description' => '説明だけ直す'])->assertSessionHasNoErrors();
        $this->updateChannel($channel, ['name' => 'RELEASE'])->assertSessionHasNoErrors();
        $this->updateChannel($channel, ['name' => 'ほかの部屋'])
            ->assertSessionHasErrors(['name' => 'そのチャンネル名はすでに使われています']);

        $this->assertSame('RELEASE', $channel->refresh()->name);
    }

    // TP-2-13（D-31）: 名前を変えないときは、同じ名前のチャンネルがほかに見えていても保存できる
    public function test_unchanged_name_can_be_saved_while_the_same_name_is_visible(): void
    {
        $mine = $this->privateChannel($this->me, ['name' => 'Plan']);
        // 自分の非公開は相手に見えないので、相手はあとから同じ名前の公開チャンネルを作れる（Q-32）
        $this->publicChannel($this->other, ['name' => 'Plan']);

        // 前後の空白を取り除いて同じなら、変えていないとみなす
        $this->updateChannel($mine, ['name' => '　Plan ', 'description' => '説明だけ直す'])
            ->assertRedirect("/channels/{$mine->id}")
            ->assertSessionHasNoErrors();
        $this->assertSame(['Plan', '説明だけ直す'], [$mine->refresh()->name, $mine->description]);

        // 英字の大小・全角半角だけの違いでも、名前を変えたものとして調べる
        foreach (['PLAN', 'Ｐlan'] as $name) {
            $this->updateChannel($mine, ['name' => $name])
                ->assertSessionHasErrors(['name' => 'そのチャンネル名はすでに使われています']);
        }
        $this->assertSame('Plan', $mine->refresh()->name);

        // 作成では、これまでどおり重複になる
        $this->createChannel(['name' => 'Plan'])
            ->assertSessionHasErrors(['name' => 'そのチャンネル名はすでに使われています']);
    }

    // TP-2-14
    public function test_non_creator_gets_403_on_a_visible_channel(): void
    {
        $public = $this->publicChannel($this->other, ['name' => '他人の公開']);
        $joined = $this->privateChannel($this->other, ['name' => '参加中の非公開']);
        $joined->members()->attach($this->me);

        foreach ([$public, $joined] as $channel) {
            $this->actingAs($this->me)->get("/channels/{$channel->id}/edit")->assertForbidden();
            $this->put("/channels/{$channel->id}", ['name' => '書き換え'])->assertForbidden();
            $this->delete("/channels/{$channel->id}", ['confirm_name' => $channel->name])->assertForbidden();
            // 入力が誤っていても、先に権限を見る
            $this->put("/channels/{$channel->id}", ['name' => ''])->assertForbidden();
            $this->delete("/channels/{$channel->id}")->assertForbidden();
        }

        $this->assertSame(['他人の公開', '参加中の非公開'], Channel::orderBy('id')->pluck('name')->all());
    }

    // TP-2-14
    public function test_non_member_gets_404_for_edit_update_and_delete(): void
    {
        $channel = $this->privateChannel($this->other, ['name' => '秘密の部屋']);

        $this->actingAs($this->me)->get("/channels/{$channel->id}/edit")->assertNotFound();
        $this->put("/channels/{$channel->id}", ['name' => '書き換え'])->assertNotFound();
        $this->put("/channels/{$channel->id}", ['name' => ''])->assertNotFound();
        $this->delete("/channels/{$channel->id}", ['confirm_name' => '秘密の部屋'])->assertNotFound();

        $this->assertSame('秘密の部屋', $channel->refresh()->name);
    }

    // TP-2-15
    public function test_delete_confirmation_shows_the_actual_counts_including_deleted(): void
    {
        $channel = $this->publicChannel($this->me);
        $first = $this->message($channel);
        $deleted = $this->message($channel, deleted: true);
        $this->message($channel, $first);
        $this->message($channel, $deleted);
        $this->message($channel, $deleted, deleted: true);
        $this->message($this->publicChannel($this->me));

        $url = "/channels/{$channel->id}/edit?confirm_delete=1";
        $this->actingAs($this->me)->get($url)->assertSee('メッセージ 2件と返信 3件も削除されます。この操作は取り消せません。確認のためチャンネル名を入力してください。');

        $this->message($channel);
        $this->get($url)->assertSee('メッセージ 3件と返信 3件も削除されます。');
    }

    // TP-2-16
    public function test_delete_confirmation_requires_an_exact_match(): void
    {
        $channel = $this->publicChannel($this->me, ['name' => 'Dev 開発']);
        $confirmUrl = route('channels.edit', ['channel' => $channel, 'confirm_delete' => 1]);

        $cases = [
            '' => 'チャンネル名を入力してください',
            '　 ' => 'チャンネル名を入力してください',
            'dev 開発' => 'チャンネル名が一致しません',
            'Ｄｅｖ 開発' => 'チャンネル名が一致しません',
            'Dev　開発' => 'チャンネル名が一致しません',
            'Dev 開' => 'チャンネル名が一致しません',
        ];
        foreach ($cases as $input => $message) {
            $this->deleteChannel($channel, $input)
                ->assertRedirect($confirmUrl)
                ->assertSessionHasErrors(['confirm_name' => $message]);
        }
        $this->assertDatabaseCount('channels', 1);

        // 戻った画面は、確認を開いたまま、エラーと入力した値が残る
        $this->deleteChannel($channel, 'dev 開発');
        $this->get($confirmUrl)
            ->assertSee('「Dev 開発」を削除しますか？')
            ->assertSee('チャンネル名が一致しません')
            ->assertSee('value="dev 開発"', false);

        $this->deleteChannel($channel, "　Dev 開発 \n")->assertRedirect('/channels');
        $this->assertDatabaseCount('channels', 0);
    }

    // TP-2-17
    public function test_deleting_a_channel_removes_its_messages_replies_and_members(): void
    {
        $channel = $this->privateChannel($this->me);
        $channel->members()->attach($this->other);
        $parent = $this->message($channel, deleted: true);
        $this->message($channel, $parent);
        $this->message($channel);
        $kept = $this->privateChannel($this->me);
        $keptMessage = $this->message($kept);

        $this->deleteChannel($channel, $channel->name)->assertRedirect('/channels');

        $this->assertSame([$kept->id], Channel::pluck('id')->all());
        $this->assertSame([$keptMessage->id], Message::pluck('id')->all());
        $this->assertSame([$kept->id], DB::table('channel_user')->pluck('channel_id')->all());
        $this->assertSame([$kept->name], $this->sidebarNames($this->get('/channels')));
    }

    // TP-2-18
    public function test_confirmation_opens_only_with_confirm_delete_1(): void
    {
        $channel = $this->publicChannel($this->me, ['name' => '開発']);
        $this->actingAs($this->me);

        $this->get("/channels/{$channel->id}/edit?confirm_delete=1")
            ->assertSee('「開発」を削除しますか？')
            ->assertSee('placeholder="開発"', false);

        foreach (['', '?confirm_delete=0', '?confirm_delete=2', '?confirm_delete=true', '?confirm_delete=', '?confirm_delete[]=1'] as $query) {
            $this->get("/channels/{$channel->id}/edit{$query}")
                ->assertOk()
                ->assertSee('このチャンネルを削除します')
                ->assertDontSee('を削除しますか？');
        }
    }

    // TP-2-19
    public function test_missing_and_invisible_channels_return_the_same_404(): void
    {
        config(['app.debug' => false]);
        $public = $this->publicChannel($this->other);
        $secret = $this->privateChannel($this->other, ['name' => '秘密の部屋']);
        $this->actingAs($this->me);

        foreach (['', '/edit'] as $suffix) {
            $missing = $this->get("/channels/999999{$suffix}")->assertNotFound();
            $hidden = $this->get("/channels/{$secret->id}{$suffix}")->assertNotFound();

            $this->assertSame($missing->getContent(), $hidden->getContent());
            $hidden->assertDontSee('秘密の部屋');
        }

        // 数字で始まる別の文字列が、そのIDのチャンネルとして開かない
        $this->get("/channels/{$public->id}abc")->assertNotFound();
    }

    // TP-2-19: 未ログインなら、チャンネルがあるかどうかに関わらずログインへ移る
    public function test_guests_are_sent_to_login_for_any_channel_url(): void
    {
        $secret = $this->privateChannel($this->other);

        foreach (['/channels/create', "/channels/{$secret->id}", '/channels/999999', "/channels/{$secret->id}/edit"] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    // 初期データ（TP-8-01 のチャンネルの分。D-30）。何度流しても増えない
    public function test_seeded_channels(): void
    {
        User::query()->delete();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $rows = Channel::inListOrder()->with(['creator', 'members'])->get()->map(fn (Channel $channel) => [
            $channel->name,
            $channel->is_private,
            $channel->creator->name,
            $channel->members->pluck('name')->all(),
        ])->all();

        $this->assertSame([
            ['全体連絡', false, '鈴木 花子', []],
            ['開発', false, '佐藤 太郎', []],
            ['雑談', false, '高橋 健', []],
            ['採用プロジェクト', true, '佐藤 太郎', ['佐藤 太郎']],
        ], $rows);

        $response = $this->actingAs(User::where('email', 'takahashi@example.com')->sole())->get('/channels');
        $this->assertSame(['全体連絡', '開発', '雑談'], $this->sidebarNames($response));
        $this->assertSame(['全体連絡', '開発', '雑談'], $this->listNames($response));
    }
}
