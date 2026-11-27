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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * メッセージ（実装単位 4）。観点は docs/design/acceptance.md の 2-4（TP-4-01〜18）と、2-8 の初期データの分。
 * 次の部分は、ほかのテストで確かめる。
 * TP-4-05 の「スレッドを開いていれば開いたまま」、TP-4-11 の thread と返信の編集 → ThreadTest（実装単位 5）。
 * 次の部分は、後の実装単位のテストに回す。
 * TP-4-07 の「検索に出る・出ない」→ 実装単位 6（ここでは、検索が使う写しの列が直ることを確かめる）。
 * TP-4-14 の「マウスを乗せたときだけ」は CSS なので、ブラウザで確かめる。
 */
class MessageTest extends TestCase
{
    use RefreshDatabase;

    private const ROW = '//div[@class="msg-list"]/article';

    private User $me;

    private User $other;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['name' => '佐藤 太郎']);
        $this->other = User::factory()->create(['name' => '鈴木 花子']);
        $this->channel = Channel::factory()->create(['name' => '開発', 'creator_id' => $this->me->id]);
    }

    /** @param array<string, mixed> $attributes */
    private function message(?User $author = null, array $attributes = [], bool $deleted = false): Message
    {
        $factory = Message::factory();

        return ($deleted ? $factory->deleted() : $factory)->create([
            'channel_id' => $this->channel->id,
            'user_id' => ($author ?? $this->me)->id,
            ...$attributes,
        ]);
    }

    private function open(string $query = '', ?Channel $channel = null): TestResponse
    {
        $channel ??= $this->channel;

        return $this->actingAs($this->me)->get("/channels/{$channel->id}{$query}");
    }

    private function postBody(mixed $body, ?Channel $channel = null): TestResponse
    {
        $channel ??= $this->channel;

        return $this->actingAs($this->me)->from("/channels/{$channel->id}")
            ->post("/channels/{$channel->id}/messages", ['body' => $body]);
    }

    private function updateMessage(Message $message, mixed $body, ?Channel $channel = null): TestResponse
    {
        $channel ??= $this->channel;

        return $this->actingAs($this->me)->from("/channels/{$channel->id}?edit={$message->id}")
            ->put("/channels/{$channel->id}/messages/{$message->id}", ['edit_body' => $body]);
    }

    private function deleteMessage(Message $message, ?Channel $channel = null): TestResponse
    {
        $channel ??= $this->channel;

        return $this->actingAs($this->me)->delete("/channels/{$channel->id}/messages/{$message->id}");
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
            $texts[] = trim($node->textContent);
        }

        return $texts;
    }

    private function row(DOMXPath $xpath, Message $message): DOMElement
    {
        return $xpath->query(self::ROW."[@id=\"message-{$message->id}\"]")->item(0);
    }

    /** @return list<string> 行に出ている操作の名前 */
    private function actions(DOMXPath $xpath, Message $message): array
    {
        $names = [];
        foreach ($xpath->query('./div[@class="msg__acts"]//*[@aria-label and not(self::svg)]', $this->row($xpath, $message)) as $node) {
            $names[] = $node->getAttribute('aria-label');
        }

        return $names;
    }

    /** 本文の要素の文字（空白と改行もそのまま。末尾の「編集済み」は含めない） */
    private function bodyText(DOMXPath $xpath, Message $message): string
    {
        return $xpath->query('.//div[@class="msg__body"]/text()', $this->row($xpath, $message))->item(0)->textContent;
    }

    // TP-4-01
    public function test_lists_all_messages_from_oldest_with_day_separators(): void
    {
        $later = $this->message(attributes: ['created_at' => '2026-08-17 14:32:00', 'body' => '後']);
        $first = $this->message($this->other, ['created_at' => '2026-08-16 23:59:00', 'body' => '先']);
        // 同じ日時なら、ID の小さい順
        $sameTime = $this->message(attributes: ['created_at' => '2026-08-17 14:32:00', 'body' => '同時']);
        // ページ送りは無いので、件数が多くても全部出る
        Message::factory()->count(60)->create([
            'channel_id' => $this->channel->id,
            'created_at' => '2026-08-18 09:00:00',
        ]);

        $xpath = $this->xpath($this->open()->assertOk());

        $ids = array_map(
            fn (DOMElement $row) => $row->getAttribute('id'),
            iterator_to_array($xpath->query(self::ROW))
        );
        $this->assertCount(63, $ids);
        $this->assertSame(
            ["message-{$first->id}", "message-{$later->id}", "message-{$sameTime->id}"],
            array_slice($ids, 0, 3)
        );
        $this->assertSame(
            ['2026年8月16日（日）', '2026年8月17日（月）', '2026年8月18日（火）'],
            $this->texts($xpath, '//div[@class="day-sep"]')
        );
        $this->assertSame(['2026/08/17 14:32'], $this->texts($xpath, './/span[@class="msg__time"]', $this->row($xpath, $later)));
        $this->assertSame(['鈴木 花子'], $this->texts($xpath, './/span[@class="msg__name"]', $this->row($xpath, $first)));
        $this->assertSame(['鈴木'], $this->texts($xpath, './/span[contains(@class, "avatar")]', $this->row($xpath, $first)));
    }

    // TP-4-01: チャンネルの流れには、返信も、ほかのチャンネルのメッセージも混ぜない
    public function test_list_excludes_replies_and_other_channels(): void
    {
        $parent = $this->message();
        Message::factory()->replyTo($parent)->create();
        Message::factory()->create();

        $xpath = $this->xpath($this->open());

        $this->assertSame(1, $xpath->query(self::ROW)->length);
    }

    // 投稿者と返信件数は、まとめて読む（行が増えても問い合わせは増えない）
    public function test_query_count_does_not_grow_with_messages(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->open()->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->message();
        $withOne = $count();

        $this->message($this->other);
        $this->message(User::factory()->create());
        $this->message();

        $this->assertSame($withOne, $count());
    }

    // TP-4-02
    public function test_body_is_shown_as_written(): void
    {
        $body = "1行目\n\n  字下げと    続く空白\n<b>太字</b> https://example.com @佐藤";
        $message = $this->message(attributes: ['body' => $body]);

        $response = $this->open();
        $xpath = $this->xpath($response);

        $this->assertSame($body, $this->bodyText($xpath, $message));
        $this->assertSame(0, $xpath->query('.//div[@class="msg__body"]//b | .//div[@class="msg__body"]//a', $this->row($xpath, $message))->length);
        $response->assertSee('&lt;b&gt;太字&lt;/b&gt;', false);
    }

    // TP-4-03
    #[DataProvider('blankBodies')]
    public function test_blank_body_is_an_error(mixed $body): void
    {
        $this->postBody($body)
            ->assertRedirect("/channels/{$this->channel->id}")
            ->assertSessionHasErrors(['body' => 'メッセージを入力してください']);

        $this->assertSame(0, Message::count());
    }

    /** @return array<string, array{mixed}> */
    public static function blankBodies(): array
    {
        return [
            '空' => [''],
            '空白と改行だけ' => [" 　\r\n\n\t "],
            '文字でない（画面を通さずに送ったとき）' => [['配列']],
        ];
    }

    // TP-4-03
    public function test_body_is_trimmed_and_inner_spaces_are_kept(): void
    {
        $this->postBody("　\r\n 1行目  と\r\n\r\n  2行目 \r\n　");

        $message = Message::sole();
        $this->assertSame("1行目  と\n\n  2行目", $message->body);
        $this->assertSame("1行目  と\n\n  2行目", $message->body_normalized);
        $this->assertSame($this->me->id, $message->user_id);
        $this->assertSame($this->channel->id, $message->channel_id);
        $this->assertNull($message->parent_id);
        $this->assertFalse($message->refresh()->is_edited);
    }

    // TP-4-04
    #[DataProvider('bodyLengths')]
    public function test_body_length_limit(string $body, bool $passes): void
    {
        $existing = $this->message(attributes: ['body' => '元の本文']);

        // 入力エラーは次の送信で上書きされるので、送るたびに確かめる
        $assertResult = fn (TestResponse $response, string $field) => $passes
            ? $response->assertSessionHasNoErrors()
            : $response->assertSessionHasErrors([$field => 'メッセージは1,000文字以内で入力してください']);

        $assertResult($this->postBody($body), 'body');
        $assertResult($this->updateMessage($existing, $body), 'edit_body');

        $this->assertSame($passes ? 2 : 1, Message::count());
        $this->assertSame(! $passes, $existing->refresh()->body === '元の本文');
    }

    /** @return array<string, array{string, bool}> */
    public static function bodyLengths(): array
    {
        return [
            '半角1,000文字' => [str_repeat('a', 1000), true],
            '全角1,000文字' => [str_repeat('あ', 1000), true],
            '半角1,001文字' => [str_repeat('a', 1001), false],
            '全角1,001文字' => [str_repeat('あ', 1001), false],
            '前後の空白は数えない' => ['  '.str_repeat('あ', 1000)."\r\n　", true],
            '改行は CRLF で届いても1文字' => [str_repeat("a\r\n", 499).'aa', true],
            '半角カナの濁点は、本文では2文字' => [str_repeat('ｶﾞ', 500), true],
        ];
    }

    // TP-4-05
    public function test_each_operation_returns_to_the_row(): void
    {
        $response = $this->postBody('書きました');
        $message = Message::sole();
        $row = "/channels/{$this->channel->id}#message-{$message->id}";

        $response->assertRedirect($row);
        $this->updateMessage($message, '直しました')->assertRedirect($row);
        $this->deleteMessage($message)->assertRedirect($row);
    }

    // TP-4-06
    public function test_only_the_editing_row_becomes_a_form(): void
    {
        $target = $this->message(attributes: ['body' => "いまの本文\n2行目"]);
        $another = $this->message(attributes: ['body' => 'ほかの本文']);

        // 通常の状態の、編集アイコンの行き先は、その行の位置（D-32）
        $this->open()->assertSee("/channels/{$this->channel->id}?edit={$target->id}#message-{$target->id}", false);

        $xpath = $this->xpath($this->open("?edit={$target->id}")->assertOk());

        $this->assertSame(1, $xpath->query('//textarea[@name="edit_body"]')->length);
        $this->assertSame(
            "いまの本文\n2行目",
            $xpath->query('.//textarea[@name="edit_body"]', $this->row($xpath, $target))->item(0)->textContent
        );
        $this->assertSame(0, $xpath->query('.//div[@class="msg__body"]', $this->row($xpath, $target))->length);
        $this->assertSame('ほかの本文', $this->bodyText($xpath, $another));
        $this->assertSame(['保存'], $this->texts($xpath, './/form[@class="msg-edit"]//button', $this->row($xpath, $target)));

        $cancel = $xpath->query('.//form[@class="msg-edit"]//a', $this->row($xpath, $target))->item(0);
        $this->assertSame('やめる', $cancel->textContent);
        $this->assertSame(
            url("/channels/{$this->channel->id}")."#message-{$target->id}",
            $cancel->getAttribute('href')
        );
    }

    // TP-4-07
    public function test_editing_marks_the_message_and_updates_the_search_copy(): void
    {
        $message = $this->message(attributes: ['body' => '直す前']);

        $this->updateMessage($message, 'ＡＦＴＥＲ　ﾃｽﾄ');

        $message->refresh();
        $this->assertSame('ＡＦＴＥＲ　ﾃｽﾄ', $message->body);
        $this->assertSame('after テスト', $message->body_normalized);
        $this->assertTrue($message->is_edited);

        // 元の本文に戻しても、「編集済み」は戻らない
        $this->updateMessage($message, '直す前');
        $this->assertTrue($message->refresh()->is_edited);

        $xpath = $this->xpath($this->open());
        $this->assertSame(['編集済み'], $this->texts($xpath, './/span[@class="msg__edited"]', $this->row($xpath, $message)));
    }

    // TP-4-08
    #[DataProvider('invalidEdits')]
    public function test_edit_error_returns_to_the_editing_row(string $body, string $error): void
    {
        $message = $this->message(attributes: ['body' => '元の本文']);

        $this->updateMessage($message, $body)
            ->assertRedirect("/channels/{$this->channel->id}?edit={$message->id}#message-{$message->id}")
            ->assertSessionHasErrors(['edit_body' => $error])
            ->assertSessionDoesntHaveErrors(['body']);

        $xpath = $this->xpath($this->open("?edit={$message->id}"));
        $this->assertSame([$error], $this->texts($xpath, '//span[@class="err"]'));
        $this->assertSame(1, $xpath->query('//form[@class="msg-edit"]//span[@class="err"]')->length);
        $this->assertSame(trim($body), $xpath->query('//textarea[@name="edit_body"]')->item(0)->textContent);
        $this->assertSame('', $xpath->query('//textarea[@name="body"]')->item(0)->textContent);
        $this->assertSame('元の本文', $message->refresh()->body);
        $this->assertFalse($message->is_edited);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidEdits(): array
    {
        return [
            '空' => [' ', 'メッセージを入力してください'],
            '長すぎる' => [str_repeat('あ', 1001), 'メッセージは1,000文字以内で入力してください'],
        ];
    }

    // TP-8-04 の投稿欄の分: エラーと値は投稿欄にだけ出る
    public function test_post_error_is_shown_only_under_the_composer(): void
    {
        $message = $this->message(attributes: ['body' => '元の本文']);
        $long = str_repeat('あ', 1001);

        $this->actingAs($this->me)->from("/channels/{$this->channel->id}?edit={$message->id}")
            ->post("/channels/{$this->channel->id}/messages", ['body' => $long])
            ->assertRedirect("/channels/{$this->channel->id}?edit={$message->id}");

        $xpath = $this->xpath($this->open("?edit={$message->id}"));
        $this->assertSame(1, $xpath->query('//span[@class="err"]')->length);
        $this->assertSame(1, $xpath->query('//form[contains(@class, "composer") and contains(@class, "is-error")]//span[@class="err"]')->length);
        $this->assertSame($long, $xpath->query('//textarea[@name="body"]')->item(0)->textContent);
        $this->assertSame('元の本文', $xpath->query('//textarea[@name="edit_body"]')->item(0)->textContent);
    }

    // TP-4-09
    public function test_others_messages_cannot_be_changed_even_by_the_channel_creator(): void
    {
        // $this->me はチャンネルの作成者
        $message = $this->message($this->other, ['body' => '他人の本文']);

        $this->updateMessage($message, '書き換え')->assertForbidden();
        // 権限は、入力チェックより先に確かめる
        $this->updateMessage($message, '')->assertForbidden();
        $this->deleteMessage($message)->assertForbidden();

        $message->refresh();
        $this->assertSame('他人の本文', $message->body);
        $this->assertNull($message->deleted_at);
    }

    // TP-4-10
    public function test_deleted_messages_cannot_be_changed(): void
    {
        $message = $this->message(attributes: ['body' => '消した本文', 'deleted_at' => '2026-08-17 10:05:00'], deleted: true);

        $this->updateMessage($message, '書き換え')->assertForbidden();
        $this->deleteMessage($message)->assertForbidden();

        $message->refresh();
        $this->assertSame('消した本文', $message->body);
        $this->assertSame('2026-08-17 10:05:00', $message->deleted_at->format('Y-m-d H:i:s'));
    }

    // TP-4-11
    public function test_edit_query_for_uneditable_messages_shows_the_normal_view(): void
    {
        $mine = $this->message();
        $uneditable = [
            $this->message($this->other),
            $this->message(deleted: true),
            // thread の無い返信の ID（D-26）
            Message::factory()->replyTo($mine)->create(['user_id' => $this->me->id]),
        ];

        foreach ($uneditable as $message) {
            $this->open("?edit={$message->id}")->assertOk()->assertDontSee('edit_body', false);
        }
    }

    // TP-4-11
    public function test_edit_query_outside_the_channel_is_not_found(): void
    {
        $mine = $this->message();
        $elsewhere = Message::factory()->create(['user_id' => $this->me->id]);

        foreach (["{$elsewhere->id}", '999999', "{$mine->id}abc", 'abc', '-1'] as $id) {
            $this->open("?edit={$id}")->assertNotFound();
        }
        $this->open("?edit[]={$mine->id}")->assertNotFound();
        // 値が空なら、指定が無いのと同じ。ほかのクエリは無視する
        $this->open('?edit=')->assertOk()->assertDontSee('edit_body', false);
        $this->open('?foo=1')->assertOk()->assertDontSee('edit_body', false);
    }

    // TP-4-12
    public function test_message_of_another_channel_is_not_found(): void
    {
        $other = Channel::factory()->create(['creator_id' => $this->me->id]);
        $message = $this->message(attributes: ['body' => '開発の本文']);

        $this->updateMessage($message, '書き換え', $other)->assertNotFound();
        $this->deleteMessage($message, $other)->assertNotFound();
        $this->actingAs($this->me)->delete("/channels/{$this->channel->id}/messages/{$message->id}abc")->assertNotFound();

        $this->assertSame('開発の本文', $message->refresh()->body);
        $this->assertNull($message->deleted_at);
    }

    // TP-4-13
    public function test_deleting_keeps_the_row_and_hides_the_body(): void
    {
        $message = $this->message(attributes: ['body' => '消す本文 KEYWORD', 'is_edited' => true]);
        Message::factory()->replyTo($message)->create();
        Message::factory()->replyTo($message)->deleted()->create();

        $this->deleteMessage($message);

        $message->refresh();
        $this->assertNotNull($message->deleted_at);
        $this->assertSame('消す本文 KEYWORD', $message->body);
        $this->assertSame('消す本文 keyword', $message->body_normalized);

        $response = $this->open()->assertDontSee('KEYWORD')->assertDontSee('keyword')->assertDontSee('編集済み');
        $xpath = $this->xpath($response);
        $row = $this->row($xpath, $message);

        $this->assertSame(['このメッセージは削除されました'], $this->texts($xpath, './/div[@class="msg__deleted-note"]', $row));
        $this->assertSame(['佐藤 太郎'], $this->texts($xpath, './/span[@class="msg__name"]', $row));
        $this->assertCount(1, $this->texts($xpath, './/span[@class="msg__time"]', $row));
        // 削除された返信も数える
        $this->assertSame(['返信2件'], $this->texts($xpath, './/a[@class="thread-link"]', $row));
        $this->assertSame(['返信'], $this->actions($xpath, $message));
    }

    // TP-4-13: 削除されたメッセージを ?edit= で指定しても、本文は出ない
    public function test_edit_query_does_not_reveal_a_deleted_body(): void
    {
        $message = $this->message(attributes: ['body' => 'SECRET'], deleted: true);

        $this->open("?edit={$message->id}")->assertOk()->assertDontSee('SECRET');
    }

    public function test_reply_count_link_is_hidden_without_replies(): void
    {
        $this->message();

        $this->assertSame(0, $this->xpath($this->open())->query('//a[@class="thread-link"]')->length);
    }

    // TP-4-14（AC-4-4）
    public function test_edit_and_delete_actions_only_on_own_messages(): void
    {
        $mine = $this->message();
        $theirs = $this->message($this->other);
        $deleted = $this->message(deleted: true);

        $xpath = $this->xpath($this->open());

        $this->assertSame(['返信', '編集', '削除'], $this->actions($xpath, $mine));
        $this->assertSame(['返信'], $this->actions($xpath, $theirs));
        $this->assertSame(['返信'], $this->actions($xpath, $deleted));
    }

    // TP-4-15
    public function test_empty_channel_shows_guidance_and_composer(): void
    {
        $response = $this->open()
            ->assertSee('まだメッセージはありません')
            ->assertSee('下の欄から最初のメッセージを送ってください')
            ->assertSee('1,000文字まで');

        $this->assertSame(
            '#開発 にメッセージを送る',
            $this->xpath($response)->query('//textarea[@name="body"]')->item(0)->getAttribute('placeholder')
        );

        $this->message();
        $this->open()->assertDontSee('まだメッセージはありません');
    }

    // TP-4-16
    public function test_hidden_channel_is_not_found(): void
    {
        $secret = Channel::factory()->private()->create(['creator_id' => $this->other->id]);

        $this->postBody('書き込み', $secret)->assertNotFound();
        // 入力に誤りがあっても、エラーを返さず 404
        $this->postBody('', $secret)->assertNotFound();

        $this->assertSame(0, Message::count());
    }

    // TP-4-16 と D-16: 外された人は、自分のメッセージも直せない・消せない（404）
    public function test_removed_member_cannot_change_own_messages(): void
    {
        $secret = Channel::factory()->private()->create(['creator_id' => $this->other->id]);
        $secret->members()->attach($this->me);
        $message = Message::factory()->create([
            'channel_id' => $secret->id, 'user_id' => $this->me->id, 'body' => '自分の本文',
        ]);

        $this->postBody('メンバーなら書ける', $secret)->assertSessionHasNoErrors()->assertRedirect();

        $secret->members()->detach($this->me);

        $this->updateMessage($message, '書き換え', $secret)->assertNotFound();
        $this->deleteMessage($message, $secret)->assertNotFound();
        $this->open("?edit={$message->id}", $secret)->assertNotFound();

        $this->assertSame('自分の本文', $message->refresh()->body);
        $this->assertNull($message->deleted_at);
    }

    public function test_guest_is_sent_to_login(): void
    {
        $message = $this->message();

        $this->post("/channels/{$this->channel->id}/messages", ['body' => '本文'])->assertRedirect('/login');
        $this->put("/channels/{$this->channel->id}/messages/{$message->id}", ['edit_body' => '本文'])->assertRedirect('/login');
        $this->delete("/channels/{$this->channel->id}/messages/{$message->id}")->assertRedirect('/login');

        $this->assertSame(1, Message::count());
        $this->assertNull($message->refresh()->deleted_at);
    }

    // TP-4-17
    public function test_old_messages_can_be_changed(): void
    {
        $message = $this->message(attributes: ['created_at' => '2020-01-01 00:00:00']);

        $this->updateMessage($message, '何年たっても直せる')->assertRedirect();
        $this->assertSame('何年たっても直せる', $message->refresh()->body);

        $this->deleteMessage($message)->assertRedirect();
        $this->assertNotNull($message->refresh()->deleted_at);
    }

    // TP-4-18
    public function test_saving_without_changes_does_not_mark_as_edited(): void
    {
        $message = $this->message(attributes: ['body' => "1行目\n2行目", 'updated_at' => '2026-08-17 10:00:00']);

        $this->updateMessage($message, "　 1行目\r\n2行目 \r\n")
            ->assertSessionHasNoErrors()
            ->assertRedirect("/channels/{$this->channel->id}#message-{$message->id}");

        $message->refresh();
        $this->assertSame("1行目\n2行目", $message->body);
        $this->assertFalse($message->is_edited);
        $this->assertSame('2026-08-17 10:00:00', $message->updated_at->format('Y-m-d H:i:s'));
    }

    // 削除済みを除く条件（検索・公開APIが、のちに通す）
    public function test_not_deleted_scope_excludes_deleted_messages(): void
    {
        $kept = $this->message();
        $this->message(deleted: true);

        $this->assertSame([$kept->id], Message::notDeleted()->pluck('id')->all());
    }

    // TP-8-01〜03 の、メッセージの分（返信は ThreadTest）。何度流しても増えない
    public function test_seeded_messages(): void
    {
        Channel::query()->delete();
        User::query()->delete();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            ['全体連絡' => 3, '開発' => 8, '雑談' => 2, '採用プロジェクト' => 3],
            Channel::inListOrder()->withCount('topLevelMessages')->get()->pluck('top_level_messages_count', 'name')->all()
        );

        $development = Channel::where('name', '開発')->sole()->topLevelMessages()->inListOrder()->with('user')->get();
        $this->assertSame(
            [
                '09:12 鈴木 花子', '09:20 高橋 健', '09:31 佐藤 太郎', '10:05 高橋 健',
                '10:48 鈴木 花子', '11:15 佐藤 太郎', '13:40 高橋 健', '14:32 佐藤 太郎',
            ],
            $development->map(fn (Message $message) => $message->created_at->format('H:i').' '.$message->user->name)->all()
        );
        $this->assertSame(['09:20'], $development->where('is_edited', true)->map(fn (Message $m) => $m->created_at->format('H:i'))->values()->all());
        $this->assertSame(['10:05'], $development->whereNotNull('deleted_at')->map(fn (Message $m) => $m->created_at->format('H:i'))->values()->all());

        foreach (Message::all() as $message) {
            $this->assertTrue($message->created_at->betweenIncluded('2026-08-12 00:00:00', '2026-08-17 23:59:59'));
        }

        // 「リリース」を含むのは、検索の見本の3件だけ（削除済みの本文も含めて数える）
        $this->assertSame(
            [
                ['採用プロジェクト', '鈴木 花子', '2026/08/12 11:20'],
                ['全体連絡', '佐藤 太郎', '2026/08/14 17:05'],
                ['開発', '鈴木 花子', '2026/08/17 09:12'],
            ],
            Message::where('body_normalized', 'like', '%リリース%')->inListOrder()->with(['channel', 'user'])->get()
                ->map(fn (Message $m) => [$m->channel->name, $m->user->name, $m->created_at->format('Y/m/d H:i')])->all()
        );
    }
}
