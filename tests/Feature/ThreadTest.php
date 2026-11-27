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
 * スレッド（実装単位 5）。観点は docs/design/acceptance.md の 2-5（TP-5-01〜12）と、
 * 2-4 のスレッドの分（TP-4-05・TP-4-08・TP-4-11）、2-8 の初期データの返信の分。
 * 「マウスを乗せたときだけ」と、パネルの中のスクロールは CSS なので、ブラウザで確かめる。
 */
class ThreadTest extends TestCase
{
    use RefreshDatabase;

    private const FLOW_ROW = '//div[@class="msg-list"]/article';

    private const ORIGIN_ROW = '//aside[@class="thread"]//div[@class="thread__origin"]/article';

    private const REPLY_ROW = '//aside[@class="thread"]/div[@class="thread__body"]/article';

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

    /** @param array<string, mixed> $attributes */
    private function reply(Message $parent, ?User $author = null, array $attributes = [], bool $deleted = false): Message
    {
        $factory = Message::factory()->replyTo($parent);

        return ($deleted ? $factory->deleted() : $factory)->create([
            'user_id' => ($author ?? $this->me)->id,
            ...$attributes,
        ]);
    }

    private function path(string $suffix = '', ?Channel $channel = null): string
    {
        $channel ??= $this->channel;

        return "/channels/{$channel->id}{$suffix}";
    }

    private function open(string $query = '', ?Channel $channel = null): TestResponse
    {
        return $this->actingAs($this->me)->get($this->path($query, $channel));
    }

    private function openThread(Message $origin, string $more = ''): TestResponse
    {
        return $this->open("?thread={$origin->id}{$more}");
    }

    private function postReply(int|string $originId, mixed $body, ?Channel $channel = null): TestResponse
    {
        return $this->actingAs($this->me)->from($this->path("?thread={$originId}", $channel))
            ->post($this->path("/messages/{$originId}/replies", $channel), ['reply_body' => $body]);
    }

    /** @param array<string, mixed> $data */
    private function postMessage(array $data): TestResponse
    {
        return $this->actingAs($this->me)->from($this->path())->post($this->path('/messages'), $data);
    }

    /** @param array<string, mixed> $data */
    private function updateMessage(Message $message, array $data): TestResponse
    {
        return $this->actingAs($this->me)->from($this->path())->put($this->path("/messages/{$message->id}"), $data);
    }

    /** @param array<string, mixed> $data */
    private function deleteMessage(Message $message, array $data = []): TestResponse
    {
        return $this->actingAs($this->me)->delete($this->path("/messages/{$message->id}"), $data);
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

    /** @return list<string> */
    private function attributes(DOMXPath $xpath, string $query, string $name, ?DOMElement $context = null): array
    {
        $values = [];
        foreach ($xpath->query($query, $context) as $node) {
            $values[] = $node->getAttribute($name);
        }

        return $values;
    }

    private function flowRow(DOMXPath $xpath, Message $message): DOMElement
    {
        return $xpath->query(self::FLOW_ROW."[@id=\"message-{$message->id}\"]")->item(0);
    }

    private function replyRow(DOMXPath $xpath, Message $reply): DOMElement
    {
        return $xpath->query(self::REPLY_ROW."[@id=\"reply-{$reply->id}\"]")->item(0);
    }

    /** @return list<string> 行に出ている操作の名前 */
    private function actions(DOMXPath $xpath, DOMElement $row): array
    {
        return $this->attributes($xpath, './div[@class="msg__acts"]//*[@aria-label and not(self::svg)]', 'aria-label', $row);
    }

    /** 欄の入っているフォームが送る thread。送らなければ空の配列 */
    private function threadFields(DOMXPath $xpath, string $form, ?DOMElement $context = null): array
    {
        return $this->attributes($xpath, "{$form}//input[@name=\"thread\"]", 'value', $context);
    }

    private function textarea(DOMXPath $xpath, string $name): string
    {
        return $xpath->query("//textarea[@name=\"{$name}\"]")->item(0)->textContent;
    }

    // TP-5-01
    public function test_thread_shows_the_origin_and_replies_from_oldest(): void
    {
        $origin = $this->message($this->other, ['body' => '元の本文', 'created_at' => '2026-08-17 09:12:00']);
        $later = $this->reply($origin, attributes: ['body' => '後の返信', 'created_at' => '2026-08-17 10:00:00']);
        $first = $this->reply($origin, $this->other, ['body' => '先の返信', 'created_at' => '2026-08-17 09:30:00']);
        // 同じ日時なら、ID の小さい順
        $sameTime = $this->reply($origin, attributes: ['body' => '同時の返信', 'created_at' => '2026-08-17 10:00:00']);
        $another = $this->message();
        $this->reply($another, attributes: ['body' => 'ほかのスレッドの返信']);

        $response = $this->openThread($origin)->assertOk()->assertDontSee('ほかのスレッドの返信');
        $xpath = $this->xpath($response);

        $this->assertSame(1, $xpath->query('//div[@class="app__body app__body--thread"]/aside[@class="thread"]')->length);
        $this->assertSame(['スレッド'], $this->texts($xpath, '//div[@class="thread__head"]//div[@class="t-label"]'));
        // 記号と名前の間の改行は、CSS で詰める
        $this->assertSame(
            '#開発',
            preg_replace('/\s+/u', '', $xpath->query('//div[@class="thread__head"]//div[@class="t-caption"]')->item(0)->textContent)
        );

        // 上から、元のメッセージ、返信件数、返信の順
        $this->assertSame(
            ['thread__origin', 'thread__count', 'msg', 'msg', 'msg'],
            $this->attributes($xpath, '//div[@class="thread__body"]/*', 'class')
        );
        $this->assertSame(['元の本文'], $this->texts($xpath, self::ORIGIN_ROW.'//div[@class="msg__body"]'));
        $this->assertSame(['鈴木 花子'], $this->texts($xpath, self::ORIGIN_ROW.'//span[@class="msg__name"]'));
        $this->assertSame(['2026/08/17 09:12'], $this->texts($xpath, self::ORIGIN_ROW.'//span[@class="msg__time"]'));
        $this->assertSame(['返信3件'], $this->texts($xpath, '//div[@class="thread__count"]'));
        $this->assertSame(
            ["reply-{$first->id}", "reply-{$later->id}", "reply-{$sameTime->id}"],
            $this->attributes($xpath, self::REPLY_ROW, 'id')
        );
        $this->assertSame(
            ['先の返信', '後の返信', '同時の返信'],
            $this->texts($xpath, self::REPLY_ROW.'//div[@class="msg__body"]')
        );

        // 元のメッセージには、行の位置の名前・操作・返信件数のリンクを付けない
        $this->assertSame([''], $this->attributes($xpath, self::ORIGIN_ROW, 'id'));
        $this->assertSame(0, $xpath->query(self::ORIGIN_ROW.'//div[@class="msg__acts"]')->length);
        $this->assertSame(0, $xpath->query(self::ORIGIN_ROW.'//a')->length);

        // チャンネルの流れに返信は混ぜない
        $this->assertSame(
            ["message-{$origin->id}", "message-{$another->id}"],
            $this->attributes($xpath, self::FLOW_ROW, 'id')
        );

        $this->assertSame('返信を送る', $xpath->query('//aside//textarea[@name="reply_body"]')->item(0)->getAttribute('placeholder'));
        $this->assertSame(['1,000文字まで'], $this->texts($xpath, '//aside//form//span[@class="count"]'));
        $this->assertSame(['送信'], $this->texts($xpath, '//aside//form[contains(@class, "composer")]//button'));
    }

    // TP-5-01（D-36）: 開く・閉じるの行き先は、元のメッセージの行の位置
    public function test_open_and_close_links_point_to_the_origin_row(): void
    {
        $origin = $this->message();
        $this->reply($origin);
        $opened = url($this->path("?thread={$origin->id}#message-{$origin->id}"));
        $closed = url($this->path("#message-{$origin->id}"));

        $xpath = $this->xpath($this->open());
        $row = $this->flowRow($xpath, $origin);
        $this->assertSame([$opened], $this->attributes($xpath, './/a[@class="thread-link"]', 'href', $row));
        $this->assertSame([$opened], $this->attributes($xpath, './/a[@aria-label="返信"]', 'href', $row));
        // 閉じている間は、パネルが無い
        $this->assertSame(0, $xpath->query('//aside')->length);
        $this->assertSame(1, $xpath->query('//div[@class="app__body"]')->length);

        // 閉じるのは、ボタンではなくリンク。編集中でも、行き先は同じ
        foreach (['', "&edit={$origin->id}"] as $more) {
            $xpath = $this->xpath($this->openThread($origin, $more));
            $this->assertSame([$closed], $this->attributes($xpath, '//aside//a[@aria-label="閉じる"]', 'href'));
        }
    }

    // TP-5-02
    public function test_thread_without_replies(): void
    {
        $origin = $this->message();

        $this->assertSame(0, $this->xpath($this->open())->query('//a[@class="thread-link"]')->length);

        $xpath = $this->xpath($this->openThread($origin)->assertOk());
        $this->assertSame(['返信0件'], $this->texts($xpath, '//div[@class="thread__count"]'));
        $this->assertSame(0, $xpath->query(self::REPLY_ROW)->length);
        $this->assertSame(1, $xpath->query('//textarea[@name="reply_body"]')->length);
    }

    // TP-5-03
    public function test_deleted_replies_are_counted(): void
    {
        $origin = $this->message();
        $kept = $this->reply($origin);
        $this->reply($origin, deleted: true);

        $assertCount = function () use ($origin): void {
            $xpath = $this->xpath($this->openThread($origin));
            $this->assertSame(['返信2件'], $this->texts($xpath, './/a[@class="thread-link"]', $this->flowRow($xpath, $origin)));
            $this->assertSame(['返信2件'], $this->texts($xpath, '//div[@class="thread__count"]'));
            $this->assertSame(2, $xpath->query(self::REPLY_ROW)->length);
        };

        $assertCount();
        // 返信を削除しても、件数は減らない
        $this->deleteMessage($kept, ['thread' => (string) $origin->id])->assertRedirect();
        $assertCount();
    }

    // TP-5-04（AC-5-2）
    public function test_deleted_origin_can_be_opened_and_replied_to(): void
    {
        $origin = $this->message($this->other, ['body' => 'SECRET'], deleted: true);
        $this->reply($origin, attributes: ['body' => '残っている返信']);

        $response = $this->openThread($origin)->assertOk()->assertDontSee('SECRET')->assertSee('残っている返信');
        $xpath = $this->xpath($response);
        $this->assertSame(['このメッセージは削除されました'], $this->texts($xpath, self::ORIGIN_ROW.'//div[@class="msg__deleted-note"]'));
        $this->assertSame(1, $xpath->query('//textarea[@name="reply_body"]')->length);

        $this->postReply($origin->id, '続けて返信')->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(2, $origin->replies()->count());
        $this->openThread($origin)->assertSee('続けて返信')->assertDontSee('SECRET');
    }

    // TP-5-05（AC-5-4）: 返信の行に「返信」は出ない。出す操作が無い行は、枠ごと出さない
    public function test_reply_rows_have_no_reply_action(): void
    {
        $origin = $this->message();
        $mine = $this->reply($origin);
        $theirs = $this->reply($origin, $this->other);
        $deleted = $this->reply($origin, deleted: true);

        $xpath = $this->xpath($this->openThread($origin));

        $this->assertSame(['編集', '削除'], $this->actions($xpath, $this->replyRow($xpath, $mine)));
        foreach ([$theirs, $deleted] as $reply) {
            $this->assertSame(0, $xpath->query('.//div[@class="msg__acts"]', $this->replyRow($xpath, $reply))->length);
        }
        $this->assertSame(0, $xpath->query(self::REPLY_ROW.'//*[@aria-label="返信" and not(self::textarea)]')->length);
        // 左の流れの行の操作は、スレッドを開いていても変わらない
        $this->assertSame(['返信', '編集', '削除'], $this->actions($xpath, $this->flowRow($xpath, $origin)));
    }

    // TP-5-05（D-38）: 返信には返信できない。入力チェックより先に確かめる
    #[DataProvider('anyBodies')]
    public function test_reply_to_a_reply_is_not_found(mixed $body): void
    {
        $reply = $this->reply($this->message());

        $this->postReply($reply->id, $body)->assertNotFound()->assertSessionHasNoErrors();

        $this->assertSame(2, Message::count());
    }

    /** @return array<string, array{mixed}> */
    public static function anyBodies(): array
    {
        return [
            '正しい本文' => ['返信への返信'],
            '空' => [''],
            '長すぎる' => [str_repeat('あ', 1001)],
            '文字でない' => [['配列']],
        ];
    }

    // TP-5-05
    public function test_thread_query_for_a_reply_is_not_found(): void
    {
        $reply = $this->reply($this->message());

        $this->open("?thread={$reply->id}")->assertNotFound();
        $this->open("?thread={$reply->id}&edit={$reply->id}")->assertNotFound();
    }

    // TP-5-06
    public function test_thread_query_outside_the_channel_is_not_found(): void
    {
        $mine = $this->message();
        $elsewhere = Message::factory()->create(['user_id' => $this->me->id, 'body' => 'ほかのチャンネルの本文']);

        foreach (["{$elsewhere->id}", '999999', "{$mine->id}abc", 'abc', '-1', '0'] as $id) {
            $this->open("?thread={$id}")->assertNotFound()->assertDontSee('ほかのチャンネルの本文');
        }
        $this->open("?thread[]={$mine->id}")->assertNotFound();
        // thread が正しくなければ、edit が正しくても 404
        $this->open("?thread=999999&edit={$mine->id}")->assertNotFound();
        // 値が空なら、指定が無いのと同じ
        $this->open('?thread=')->assertOk()->assertDontSee('reply_body', false);
    }

    // permissions-api.md 1-6: URL のメッセージは、URL のチャンネルのもの
    public function test_reply_to_a_message_outside_the_channel_is_not_found(): void
    {
        $mine = $this->message();
        $elsewhere = Message::factory()->create();

        $this->postReply($elsewhere->id, '返信')->assertNotFound();
        $this->postReply(999999, '返信')->assertNotFound();
        $this->postReply("{$mine->id}abc", '返信')->assertNotFound();

        $this->assertSame(2, Message::count());
    }

    // permissions-api.md 1-5: 見られないチャンネルは 404。外された人も同じ
    public function test_hidden_channel_thread_is_not_found(): void
    {
        $secret = Channel::factory()->private()->create(['creator_id' => $this->other->id]);
        $origin = Message::factory()->create(['channel_id' => $secret->id, 'body' => 'SECRET']);

        $assertHidden = function () use ($secret, $origin): void {
            $this->open("?thread={$origin->id}", $secret)->assertNotFound()->assertDontSee('SECRET');
            $this->postReply($origin->id, '返信', $secret)->assertNotFound();
            // 入力に誤りがあっても、返信を指していても、同じ 404
            $this->postReply($origin->id, '', $secret)->assertNotFound()->assertSessionHasNoErrors();
        };

        $assertHidden();

        $secret->members()->attach($this->me);
        $this->open("?thread={$origin->id}", $secret)->assertOk()->assertSee('SECRET');
        $this->postReply($origin->id, 'メンバーなら返信できる', $secret)->assertSessionHasNoErrors()->assertRedirect();

        $secret->members()->detach($this->me);
        $assertHidden();

        $this->assertSame(1, $origin->replies()->count());
    }

    public function test_guest_is_sent_to_login(): void
    {
        $origin = $this->message();

        $this->post($this->path("/messages/{$origin->id}/replies"), ['reply_body' => '返信'])->assertRedirect('/login');
        $this->get($this->path("?thread={$origin->id}"))->assertRedirect('/login');

        $this->assertSame(1, Message::count());
    }

    // TP-5-07
    #[DataProvider('blankBodies')]
    public function test_blank_reply_is_an_error(mixed $body): void
    {
        $origin = $this->message();

        $this->postReply($origin->id, $body)
            ->assertRedirect($this->path("?thread={$origin->id}"))
            ->assertSessionHasErrors(['reply_body' => 'メッセージを入力してください']);

        $this->assertSame(1, Message::count());
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

    // TP-5-08: 配列は画面からは送れない。直接送られても、戻った先の画面が開けて、返信欄の下にエラーが出る
    public function test_the_thread_opens_after_an_array_is_sent(): void
    {
        $origin = $this->message();

        $this->postReply($origin->id, ['配列'])->assertRedirect($this->path("?thread={$origin->id}"));

        $xpath = $this->xpath($this->openThread($origin)->assertOk());
        $this->assertSame(
            ['メッセージを入力してください'],
            $this->texts($xpath, '//aside//form[@class="composer is-error"]//span[@class="err"]')
        );
        $this->assertSame('', $this->textarea($xpath, 'reply_body'));
    }

    // TP-5-07
    #[DataProvider('bodyLengths')]
    public function test_reply_length_limit(string $body, bool $passes): void
    {
        $response = $this->postReply($this->message()->id, $body);

        $passes
            ? $response->assertSessionHasNoErrors()
            : $response->assertSessionHasErrors(['reply_body' => 'メッセージは1,000文字以内で入力してください']);
        $this->assertSame($passes ? 2 : 1, Message::count());
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
        ];
    }

    // TP-5-08: エラーと値は、返信欄にだけ出る
    #[DataProvider('invalidReplies')]
    public function test_reply_error_is_shown_only_under_the_reply_composer(string $body, string $error): void
    {
        $origin = $this->message(attributes: ['body' => '元の本文']);
        // 左の行を編集中でも、送った画面に戻る
        $sentFrom = $this->path("?thread={$origin->id}&edit={$origin->id}");

        $this->actingAs($this->me)->from($sentFrom)
            ->post($this->path("/messages/{$origin->id}/replies"), ['reply_body' => $body])
            ->assertRedirect($sentFrom)
            ->assertSessionHasErrors(['reply_body' => $error])
            ->assertSessionDoesntHaveErrors(['body', 'edit_body']);

        $xpath = $this->xpath($this->openThread($origin, "&edit={$origin->id}"));
        $this->assertSame([$error], $this->texts($xpath, '//span[@class="err"]'));
        $this->assertSame([$error], $this->texts($xpath, '//aside//form[@class="composer is-error"]//span[@class="err"]'));
        $this->assertSame(trim($body), $this->textarea($xpath, 'reply_body'));
        $this->assertSame('', $this->textarea($xpath, 'body'));
        $this->assertSame('元の本文', $this->textarea($xpath, 'edit_body'));
        $this->assertSame(1, Message::count());
    }

    /** @return array<string, array{string, string}> */
    public static function invalidReplies(): array
    {
        return [
            '空' => [' ', 'メッセージを入力してください'],
            '長すぎる' => [str_repeat('あ', 1001), 'メッセージは1,000文字以内で入力してください'],
        ];
    }

    // TP-5-08: 投稿欄のエラーと値は、返信欄に出ない
    public function test_post_error_is_not_shown_under_the_reply_composer(): void
    {
        $origin = $this->message();
        $long = str_repeat('あ', 1001);
        $sentFrom = $this->path("?thread={$origin->id}");

        $this->actingAs($this->me)->from($sentFrom)
            ->post($this->path('/messages'), ['body' => $long, 'thread' => (string) $origin->id])
            ->assertRedirect($sentFrom);

        $xpath = $this->xpath($this->openThread($origin));
        $this->assertSame(1, $xpath->query('//span[@class="err"]')->length);
        $this->assertSame(1, $xpath->query('//main//span[@class="err"]')->length);
        $this->assertSame($long, $this->textarea($xpath, 'body'));
        $this->assertSame('', $this->textarea($xpath, 'reply_body'));
        $this->assertSame(['composer'], $this->attributes($xpath, '//aside//form[contains(@class, "composer")]', 'class'));
    }

    // TP-5-09
    public function test_own_reply_can_be_edited_and_deleted(): void
    {
        $origin = $this->message();
        $reply = $this->reply($origin, attributes: ['body' => '直す前の返信']);
        $thread = ['thread' => (string) $origin->id];

        $this->updateMessage($reply, [...$thread, 'edit_body' => '直した返信'])->assertSessionHasNoErrors();

        $this->assertTrue($reply->refresh()->is_edited);
        $xpath = $this->xpath($this->openThread($origin));
        $this->assertSame(['直した返信編集済み'], $this->texts($xpath, './/div[@class="msg__body"]', $this->replyRow($xpath, $reply)));
        $this->assertSame(['編集済み'], $this->texts($xpath, './/span[@class="msg__edited"]', $this->replyRow($xpath, $reply)));

        $this->deleteMessage($reply, $thread);

        $this->assertNotNull($reply->refresh()->deleted_at);
        $this->assertSame('直した返信', $reply->body);
        $response = $this->openThread($origin)->assertDontSee('直した返信')->assertDontSee('編集済み');
        $xpath = $this->xpath($response);
        $row = $this->replyRow($xpath, $reply);
        $this->assertSame('msg msg--deleted', $row->getAttribute('class'));
        $this->assertSame(['このメッセージは削除されました'], $this->texts($xpath, './/div[@class="msg__deleted-note"]', $row));
        $this->assertSame(['佐藤 太郎'], $this->texts($xpath, './/span[@class="msg__name"]', $row));
        $this->assertCount(1, $this->texts($xpath, './/span[@class="msg__time"]', $row));
    }

    // TP-5-09: 他人の返信と、削除済みの返信は、直接送っても 403。チャンネルの作成者でも同じ
    public function test_uneditable_replies_cannot_be_changed(): void
    {
        $origin = $this->message();
        $theirs = $this->reply($origin, $this->other, ['body' => '他人の返信']);
        $deleted = $this->reply($origin, attributes: ['body' => '消した返信'], deleted: true);

        foreach ([$theirs, $deleted] as $reply) {
            $this->updateMessage($reply, ['edit_body' => '書き換え'])->assertForbidden();
            $this->deleteMessage($reply)->assertForbidden();
        }

        $this->assertSame('他人の返信', $theirs->refresh()->body);
        $this->assertNull($theirs->deleted_at);
        $this->assertSame('消した返信', $deleted->refresh()->body);
    }

    // TP-4-06 の返信の分（D-26・D-32）
    public function test_only_the_editing_reply_becomes_a_form(): void
    {
        $origin = $this->message();
        $target = $this->reply($origin, attributes: ['body' => "いまの本文\n2行目"]);
        $this->reply($origin, attributes: ['body' => 'ほかの返信']);
        $editing = $this->path("?thread={$origin->id}&edit={$target->id}#reply-{$target->id}");

        $xpath = $this->xpath($this->openThread($origin));
        $this->assertSame(
            [url($editing)],
            $this->attributes($xpath, './/a[@aria-label="編集"]', 'href', $this->replyRow($xpath, $target))
        );

        $xpath = $this->xpath($this->openThread($origin, "&edit={$target->id}")->assertOk());
        $row = $this->replyRow($xpath, $target);
        $this->assertSame(1, $xpath->query('//textarea[@name="edit_body"]')->length);
        $this->assertSame("いまの本文\n2行目", $xpath->query('.//textarea[@name="edit_body"]', $row)->item(0)->textContent);
        $this->assertSame(0, $xpath->query('.//div[@class="msg__body"]', $row)->length);
        $this->assertSame([(string) $origin->id], $this->threadFields($xpath, './/form[@class="msg-edit"]', $row));
        $this->assertSame(
            [url($this->path("?thread={$origin->id}#reply-{$target->id}"))],
            $this->attributes($xpath, './/form[@class="msg-edit"]//a', 'href', $row)
        );
    }

    // TP-4-08 の返信の分
    public function test_reply_edit_error_returns_to_the_editing_row(): void
    {
        $origin = $this->message();
        $reply = $this->reply($origin, attributes: ['body' => '元の返信']);

        $this->updateMessage($reply, ['edit_body' => ' ', 'thread' => (string) $origin->id])
            ->assertRedirect($this->path("?thread={$origin->id}&edit={$reply->id}#reply-{$reply->id}"))
            ->assertSessionHasErrors(['edit_body' => 'メッセージを入力してください'])
            ->assertSessionDoesntHaveErrors(['body', 'reply_body']);

        $xpath = $this->xpath($this->openThread($origin, "&edit={$reply->id}"));
        $this->assertSame(1, $xpath->query('//span[@class="err"]')->length);
        $this->assertSame(1, $xpath->query('.//form[@class="msg-edit"]//span[@class="err"]', $this->replyRow($xpath, $reply))->length);
        $this->assertSame('', $this->textarea($xpath, 'body'));
        $this->assertSame('', $this->textarea($xpath, 'reply_body'));
        $this->assertSame('元の返信', $reply->refresh()->body);
    }

    // TP-5-10・TP-4-05: 返信は元のメッセージと同じチャンネルに入り、送ると返信の行の位置へ戻る
    public function test_reply_is_saved_in_the_channel_of_the_origin(): void
    {
        $origin = $this->message($this->other);
        $elsewhere = Message::factory()->create();

        $response = $this->actingAs($this->me)->post($this->path("/messages/{$origin->id}/replies"), [
            'reply_body' => "　\r\n 1行目\r\n  2行目 \r\n",
            // 入れ先は URL で決まる。フォームから送っても使わない
            'channel_id' => $elsewhere->channel_id,
            'parent_id' => $elsewhere->id,
            'thread' => (string) $elsewhere->id,
        ]);

        $reply = $origin->replies()->sole();
        $response->assertRedirect($this->path("?thread={$origin->id}#reply-{$reply->id}"));
        $this->assertSame($this->channel->id, $reply->channel_id);
        $this->assertSame($this->me->id, $reply->user_id);
        $this->assertSame("1行目\n  2行目", $reply->body);
        $this->assertSame("1行目\n  2行目", $reply->body_normalized);
        $this->assertFalse($reply->refresh()->is_edited);
        $this->assertNull($reply->deleted_at);

        // AC-5-1: 返信のいちばん下に加わり、左の流れには出ず、件数が増える
        $xpath = $this->xpath($this->openThread($origin));
        $this->assertSame(["reply-{$reply->id}"], $this->attributes($xpath, self::REPLY_ROW, 'id'));
        $this->assertSame(["message-{$origin->id}"], $this->attributes($xpath, self::FLOW_ROW, 'id'));
        $this->assertSame(['返信1件'], $this->texts($xpath, './/a[@class="thread-link"]', $this->flowRow($xpath, $origin)));
    }

    // TP-5-11・TP-4-05: 左の操作も、返信の操作も、スレッドを開いたままにする
    public function test_operations_keep_the_thread_open(): void
    {
        $origin = $this->message($this->other);
        $reply = $this->reply($origin);
        $thread = ['thread' => (string) $origin->id];
        $returnTo = fn (string $anchor) => $this->path("?thread={$origin->id}#{$anchor}");

        $response = $this->postMessage([...$thread, 'body' => '左で書く']);
        $message = Message::where('body', '左で書く')->sole();
        $this->assertNull($message->parent_id);
        $response->assertRedirect($returnTo("message-{$message->id}"));

        $this->updateMessage($message, [...$thread, 'edit_body' => '左で直す'])
            ->assertRedirect($returnTo("message-{$message->id}"));
        $this->updateMessage($message, [...$thread, 'edit_body' => ''])
            ->assertRedirect($this->path("?thread={$origin->id}&edit={$message->id}#message-{$message->id}"));
        $this->deleteMessage($message, $thread)->assertRedirect($returnTo("message-{$message->id}"));
        $this->assertNotNull($message->refresh()->deleted_at);

        $this->updateMessage($reply, [...$thread, 'edit_body' => '返信を直す'])
            ->assertRedirect($returnTo("reply-{$reply->id}"));
        $this->deleteMessage($reply, $thread)->assertRedirect($returnTo("reply-{$reply->id}"));
    }

    // TP-5-11: 開いているときだけ、左の投稿欄・編集欄・削除と、返信の削除が thread を送る
    public function test_forms_send_the_open_thread(): void
    {
        $origin = $this->message();
        $another = $this->message();
        $reply = $this->reply($origin);
        $composer = '//main//form[contains(@class, "composer")]';

        $xpath = $this->xpath($this->openThread($origin));
        $sent = [(string) $origin->id];
        $this->assertSame($sent, $this->threadFields($xpath, $composer));
        $this->assertSame($sent, $this->threadFields($xpath, './/div[@class="msg__acts"]//form', $this->flowRow($xpath, $another)));
        $this->assertSame($sent, $this->threadFields($xpath, './/div[@class="msg__acts"]//form', $this->replyRow($xpath, $reply)));
        // 返信欄は、送り先の URL で元のメッセージが分かるので送らない（permissions-api.md 2-3）
        $this->assertSame([], $this->threadFields($xpath, '//aside//form[contains(@class, "composer")]'));
        $this->assertSame(
            [url($this->path("?thread={$origin->id}&edit={$another->id}#message-{$another->id}"))],
            $this->attributes($xpath, './/a[@aria-label="編集"]', 'href', $this->flowRow($xpath, $another))
        );

        $xpath = $this->xpath($this->open());
        $this->assertSame(0, $xpath->query('//input[@name="thread"]')->length);

        $xpath = $this->xpath($this->open("?edit={$another->id}"));
        $this->assertSame(0, $xpath->query('//input[@name="thread"]')->length);
    }

    // TP-5-11（permissions-api.md 2-2）: 左の行を編集中で、スレッドも開いている
    public function test_editing_a_channel_row_while_the_thread_is_open(): void
    {
        $origin = $this->message();
        $another = $this->message();

        // 元のメッセージを編集中にしても、編集欄は左の流れの行にだけ出る
        foreach ([$origin, $another] as $target) {
            $xpath = $this->xpath($this->openThread($origin, "&edit={$target->id}")->assertOk());
            $row = $this->flowRow($xpath, $target);

            $this->assertSame(1, $xpath->query('//textarea[@name="edit_body"]')->length);
            $this->assertSame(1, $xpath->query('.//textarea[@name="edit_body"]', $row)->length);
            $this->assertSame(1, $xpath->query(self::ORIGIN_ROW.'//div[@class="msg__body"]')->length);
            $this->assertSame([(string) $origin->id], $this->threadFields($xpath, './/form[@class="msg-edit"]', $row));
            $this->assertSame(
                [url($this->path("?thread={$origin->id}#message-{$target->id}"))],
                $this->attributes($xpath, './/form[@class="msg-edit"]//a', 'href', $row)
            );
        }
    }

    // TP-5-12（D-37）: フォームの thread がスレッドの元でなければ、無いのと同じ。操作はする
    #[DataProvider('invalidThreadFields')]
    public function test_invalid_thread_field_is_ignored(string $case): void
    {
        $origin = $this->message();
        $reply = $this->reply($origin);
        $mine = $this->message();
        $thread = ['thread' => match ($case) {
            'nonexistent' => '999999',
            'other channel' => (string) Message::factory()->create()->id,
            'reply' => (string) $reply->id,
            'not a number' => 'abc',
            'number with letters' => "{$origin->id}abc",
            'array' => [(string) $origin->id],
        }];
        $row = $this->path("#message-{$mine->id}");

        $response = $this->postMessage([...$thread, 'body' => '書く']);
        $posted = Message::where('body', '書く')->sole();
        $this->assertNull($posted->parent_id);
        $response->assertRedirect($this->path("#message-{$posted->id}"));

        $this->updateMessage($mine, [...$thread, 'edit_body' => '直す'])->assertRedirect($row);
        $this->assertSame('直す', $mine->refresh()->body);

        $this->updateMessage($mine, [...$thread, 'edit_body' => ''])
            ->assertRedirect($this->path("?edit={$mine->id}#message-{$mine->id}"))
            ->assertSessionHasErrors(['edit_body']);

        $this->deleteMessage($mine, $thread)->assertRedirect($row);
        $this->assertNotNull($mine->refresh()->deleted_at);

        // 返信を直す・消すときも同じ
        $this->deleteMessage($reply, $thread)->assertRedirect($this->path("#reply-{$reply->id}"));
        $this->assertNotNull($reply->refresh()->deleted_at);
    }

    /** @return array<string, array{string}> */
    public static function invalidThreadFields(): array
    {
        return [
            'そのチャンネルに無い ID' => ['nonexistent'],
            'ほかのチャンネルのメッセージの ID' => ['other channel'],
            '返信の ID' => ['reply'],
            '数字でない' => ['not a number'],
            '数字で始まる文字' => ['number with letters'],
            '配列' => ['array'],
        ];
    }

    // TP-4-11 の返信の分（D-26）: thread がその返信の元でなければ、編集中にしない
    public function test_reply_is_edited_only_in_its_own_thread(): void
    {
        $origin = $this->message();
        $another = $this->message();
        $reply = $this->reply($origin);

        $this->open("?edit={$reply->id}")->assertOk()
            ->assertDontSee('edit_body', false)->assertDontSee('reply_body', false);

        // thread が正しい ID なら、そのスレッドは開く
        $xpath = $this->xpath($this->openThread($another, "&edit={$reply->id}")->assertOk());
        $this->assertSame(0, $xpath->query('//textarea[@name="edit_body"]')->length);
        $this->assertSame(1, $xpath->query('//textarea[@name="reply_body"]')->length);

        $this->openThread($origin, "&edit={$reply->id}")->assertOk()->assertSee('edit_body', false);
    }

    // TP-4-11 の返信の分
    public function test_uneditable_replies_stay_in_the_normal_view(): void
    {
        $origin = $this->message();
        $theirs = $this->reply($origin, $this->other);
        $deleted = $this->reply($origin, attributes: ['body' => 'SECRET'], deleted: true);

        foreach ([$theirs, $deleted] as $reply) {
            $this->openThread($origin, "&edit={$reply->id}")->assertOk()
                ->assertDontSee('edit_body', false)->assertDontSee('SECRET');
        }

        $elsewhere = Message::factory()->create(['user_id' => $this->me->id]);
        $this->openThread($origin, "&edit={$elsewhere->id}")->assertNotFound();
    }

    // 返信の投稿者は、まとめて読む（返信が増えても問い合わせは増えない）
    public function test_query_count_does_not_grow_with_replies(): void
    {
        $origin = $this->message();
        $count = function () use ($origin): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->openThread($origin)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->reply($origin);
        $withOne = $count();

        $this->reply($origin, $this->other);
        $this->reply($origin, User::factory()->create());
        $this->reply($origin);
        $this->reply($origin, deleted: true);

        $this->assertSame($withOne, $count());
    }

    // TP-8-01〜03 の返信の分。何度流しても増えない
    public function test_seeded_replies(): void
    {
        Channel::query()->delete();
        User::query()->delete();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $replies = Message::whereNotNull('parent_id')->inListOrder()->with(['user', 'channel'])->get();
        $origins = Message::whereKey($replies->pluck('parent_id'))->get()->keyBy('id');

        $this->assertSame(
            [
                '開発 09:12 ← 2026/08/17 09:18 高橋 健',
                '開発 09:12 ← 2026/08/17 09:25 佐藤 太郎',
                '開発 10:05 ← 2026/08/17 10:12 佐藤 太郎',
                '開発 10:05 ← 2026/08/17 10:48 鈴木 花子',
                '開発 10:05 ← 2026/08/17 11:02 高橋 健',
            ],
            $replies->map(fn (Message $reply) => sprintf(
                '%s %s ← %s %s',
                $reply->channel->name,
                $origins[$reply->parent_id]->created_at->format('H:i'),
                $reply->created_at->format('Y/m/d H:i'),
                $reply->user->name
            ))->all()
        );

        foreach ($replies as $reply) {
            $origin = $origins[$reply->parent_id];
            $this->assertFalse($origin->isReply());
            $this->assertSame($origin->channel_id, $reply->channel_id);
            $this->assertFalse($reply->is_edited);
            $this->assertNull($reply->deleted_at);
            $this->assertStringNotContainsString('リリース', $reply->body_normalized);
        }

        // 返信が3件付いているのは、削除されたメッセージ
        $this->assertTrue($origins->first(fn (Message $m) => $m->created_at->format('H:i') === '10:05')->isDeleted());
        $this->assertSame(13, Channel::where('name', '開発')->sole()->messages()->count());
    }
}
