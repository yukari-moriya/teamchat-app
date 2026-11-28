<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * 公開API（実装単位 7）。観点は docs/design/acceptance.md の 2-7（TP-7-01〜16）。
 * あわせて、500 の返し方（behavior.md の 3章）、使わない物を外したこと（D-10）、
 * 画面のエラーが変わらないことを確かめる。
 */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    private const CHANNELS = '/api/channels';

    private const CHANNEL_KEYS = ['id', 'name', 'description', 'creator_name', 'created_at'];

    private const MESSAGE_KEYS = ['id', 'body', 'author_name', 'created_at', 'is_edited', 'reply_count'];

    private const NOT_FOUND = '{"message":"Not Found"}';

    private User $me;

    private User $other;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['name' => '佐藤 太郎', 'email' => 'sato@example.com']);
        $this->other = User::factory()->create(['name' => '鈴木 花子', 'email' => 'suzuki@example.com']);
        $this->channel = Channel::factory()->create([
            'name' => '開発',
            'description' => '開発に関する相談と報告',
            'creator_id' => $this->me->id,
            'created_at' => '2026-08-10 09:05:00',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function message(array $attributes = [], ?Channel $channel = null): Message
    {
        return Message::factory()->create([
            'channel_id' => ($channel ?? $this->channel)->id,
            'user_id' => $this->me->id,
            ...$attributes,
        ]);
    }

    private function privateChannel(): Channel
    {
        return Channel::factory()->private()->create([
            'name' => '採用プロジェクト',
            'description' => '採用の相談',
            'creator_id' => $this->me->id,
            'created_at' => '2026-08-10 09:15:00',
        ]);
    }

    /**
     * 古い順に $count 件作る。同じ日時の行が、50件の区切りをまたぐようにする。
     *
     * @return list<int> 返るはずの順（新しい順。同じ日時なら ID の大きい順）の ID
     */
    private function messages(int $count): array
    {
        $base = Carbon::parse('2026-08-17 09:00:00');
        $ids = [];

        for ($i = 1; $i <= $count; $i++) {
            $ids[] = $this->message(['created_at' => $base->copy()->addMinutes(intdiv($i, 3))])->id;
        }

        return array_reverse($ids);
    }

    private function messagesPath(Channel|int|string|null $channel = null): string
    {
        $channel ??= $this->channel;
        $id = $channel instanceof Channel ? $channel->id : $channel;

        return "/api/channels/{$id}/messages";
    }

    /** テストの get() はブラウザ風の Accept を付けるので、ヘッダを消して送る */
    private function callWithoutAccept(string $method, string $uri): TestResponse
    {
        $response = $this->call($method, $uri, server: ['HTTP_ACCEPT' => null]);

        $this->assertNull($this->app['request']->headers->get('Accept'));

        return $response;
    }

    private function assertApiError(int $status, string $body, TestResponse $response): void
    {
        $response->assertStatus($status)->assertHeader('Content-Type', 'application/json');
        $this->assertSame($body, $response->getContent());
    }

    private static function encodeCursor(string $json): string
    {
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    // TP-7-01
    public function test_public_channels_are_listed_oldest_first_with_only_the_listed_fields(): void
    {
        // ID の順と作成日時の順が違う行と、作成日時が同じ行を混ぜる
        $same = Channel::factory()->create([
            'name' => '雑談',
            'description' => null,
            'creator_id' => $this->other->id,
            'created_at' => '2026-08-10 09:05:00',
        ]);
        $oldest = Channel::factory()->create([
            'name' => '全体連絡',
            'description' => '全員に共有したいこと',
            'creator_id' => $this->other->id,
            'created_at' => '2026-08-10 09:00:00',
        ]);

        $response = $this->get(self::CHANNELS)->assertOk();

        $this->assertSame([
            'data' => [
                [
                    'id' => $oldest->id,
                    'name' => '全体連絡',
                    'description' => '全員に共有したいこと',
                    'creator_name' => '鈴木 花子',
                    'created_at' => '2026-08-10T09:00:00+09:00',
                ],
                [
                    'id' => $this->channel->id,
                    'name' => '開発',
                    'description' => '開発に関する相談と報告',
                    'creator_name' => '佐藤 太郎',
                    'created_at' => '2026-08-10T09:05:00+09:00',
                ],
                [
                    'id' => $same->id,
                    'name' => '雑談',
                    'description' => null,
                    'creator_name' => '鈴木 花子',
                    'created_at' => '2026-08-10T09:05:00+09:00',
                ],
            ],
        ], $response->json());
    }

    // TP-7-01（ページ送りなし）
    public function test_the_channel_list_is_not_paginated(): void
    {
        Channel::factory()->count(60)->create(['creator_id' => $this->me->id]);

        $response = $this->get(self::CHANNELS)->assertOk()->assertJsonCount(61, 'data');

        $this->assertSame(['data'], array_keys($response->json()));
    }

    // TP-7-02
    public function test_private_channels_and_personal_information_are_not_returned(): void
    {
        $private = $this->privateChannel();
        $this->message(['body' => '内定の連絡'], $private);
        $this->message();

        $channels = $this->get(self::CHANNELS)->assertOk()->assertJsonCount(1, 'data');
        $messages = $this->get($this->messagesPath())->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame([$this->channel->id], $channels->json('data.*.id'));
        $this->assertSame(self::CHANNEL_KEYS, array_keys($channels->json('data.0')));
        $this->assertSame(self::MESSAGE_KEYS, array_keys($messages->json('data.0')));

        foreach ([$channels, $messages] as $response) {
            // 返事では日本語が \u の形になるので、戻してから調べる
            $body = json_encode($response->json(), JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString('採用', $body);
            $this->assertStringNotContainsString('内定', $body);
            $this->assertStringNotContainsString('@example.com', $body);
        }
    }

    // TP-7-03
    public function test_no_public_channel_gives_an_empty_list(): void
    {
        $this->channel->delete();
        $this->privateChannel();

        $response = $this->get(self::CHANNELS)->assertOk();

        $this->assertSame('{"data":[]}', $response->getContent());
    }

    // TP-7-04
    public function test_top_level_messages_are_listed_newest_first_with_only_the_listed_fields(): void
    {
        // ID の順と投稿日時の順が違う行と、投稿日時が同じ行を混ぜる
        $newest = $this->message([
            'body' => "1行目\n2行目",
            'is_edited' => true,
            'user_id' => $this->other->id,
            'created_at' => '2026-08-17 14:32:00',
        ]);
        $first = $this->message(['body' => '朝の連絡', 'created_at' => '2026-08-17 09:12:00']);
        $second = $this->message(['body' => '同じ時刻の連絡', 'created_at' => '2026-08-17 09:12:00']);

        // 返信は一覧に出さず、件数にだけ数える。削除された返信も数える
        Message::factory()->replyTo($first)->create(['body' => '返信の本文']);
        Message::factory()->replyTo($first)->deleted()->create(['body' => '消した返信の本文']);

        $this->message([], Channel::factory()->create(['creator_id' => $this->me->id]));

        $response = $this->get($this->messagesPath())->assertOk();

        $this->assertSame(['data', 'links', 'meta'], array_keys($response->json()));
        $this->assertSame([
            [
                'id' => $newest->id,
                'body' => "1行目\n2行目",
                'author_name' => '鈴木 花子',
                'created_at' => '2026-08-17T14:32:00+09:00',
                'is_edited' => true,
                'reply_count' => 0,
            ],
            [
                'id' => $second->id,
                'body' => '同じ時刻の連絡',
                'author_name' => '佐藤 太郎',
                'created_at' => '2026-08-17T09:12:00+09:00',
                'is_edited' => false,
                'reply_count' => 0,
            ],
            [
                'id' => $first->id,
                'body' => '朝の連絡',
                'author_name' => '佐藤 太郎',
                'created_at' => '2026-08-17T09:12:00+09:00',
                'is_edited' => false,
                'reply_count' => 2,
            ],
        ], $response->json('data'));
        $this->assertSame(50, $response->json('meta.per_page'));
        $this->assertNull($response->json('meta.next_cursor'));
        $this->assertNull($response->json('links.next'));
    }

    // TP-7-05
    public function test_deleted_messages_are_not_returned_at_all(): void
    {
        $kept = $this->message(['body' => '残るメッセージ']);
        $deleted = Message::factory()->deleted()->create([
            'channel_id' => $this->channel->id,
            'body' => 'ukeire0703 を含む本文',
        ]);
        // 削除されたメッセージに返信が付いていても、行は出さない
        Message::factory()->replyTo($deleted)->create();

        $response = $this->get($this->messagesPath())->assertOk();

        $this->assertSame([$kept->id], $response->json('data.*.id'));
        $this->assertStringNotContainsString('ukeire0703', $response->getContent());
    }

    // TP-7-05（画面で削除する流れ）
    public function test_a_message_deleted_on_the_screen_disappears_from_the_api(): void
    {
        $message = $this->message();

        $this->get($this->messagesPath())->assertOk()->assertJsonPath('data.0.id', $message->id);

        $this->actingAs($this->me)
            ->delete("/channels/{$this->channel->id}/messages/{$message->id}")
            ->assertRedirect();

        $this->get($this->messagesPath())->assertOk()->assertJsonCount(0, 'data');
    }

    // TP-7-06
    public function test_exactly_fifty_messages_have_no_next_cursor(): void
    {
        $ids = $this->messages(50);
        // 返信と削除されたメッセージは、50件に数えない
        Message::factory()->replyTo(Message::findOrFail($ids[0]))->create();
        Message::factory()->deleted()->create(['channel_id' => $this->channel->id]);

        $response = $this->get($this->messagesPath())->assertOk();

        $this->assertSame($ids, $response->json('data.*.id'));
        $this->assertNull($response->json('meta.next_cursor'));
        $this->assertNull($response->json('links.next'));
    }

    // TP-7-06
    public function test_the_fifty_first_message_is_fetched_with_the_next_cursor(): void
    {
        $ids = $this->messages(51);

        $first = $this->get($this->messagesPath())->assertOk();
        $cursor = $first->json('meta.next_cursor');

        $this->assertSame(array_slice($ids, 0, 50), $first->json('data.*.id'));
        $this->assertNotNull($cursor);
        $this->assertStringEndsWith($this->messagesPath()."?cursor={$cursor}", $first->json('links.next'));

        $second = $this->get($first->json('links.next'))->assertOk();

        $this->assertSame(array_slice($ids, 50), $second->json('data.*.id'));
        $this->assertNull($second->json('meta.next_cursor'));
        $this->assertNull($second->json('links.next'));

        // meta.prev_cursor の値も、壊れた cursor とは扱わない
        $previous = $this->get($this->messagesPath().'?cursor='.$second->json('meta.prev_cursor'))->assertOk();

        $this->assertSame(array_slice($ids, 0, 50), $previous->json('data.*.id'));
    }

    // TP-7-07
    public function test_a_new_message_between_pages_does_not_repeat_a_message(): void
    {
        $ids = $this->messages(51);
        $first = $this->get($this->messagesPath())->assertOk();

        $this->message(['created_at' => '2026-08-18 09:00:00']);

        $second = $this->get($this->messagesPath().'?cursor='.$first->json('meta.next_cursor'))->assertOk();

        $this->assertSame($ids, [...$first->json('data.*.id'), ...$second->json('data.*.id')]);
    }

    /**
     * どれも、受け付けてしまうと違いが出るように、全部のメッセージより古い日時を土台にする。
     *
     * @return array<string, array{string}>
     */
    public static function brokenCursors(): array
    {
        $date = '"created_at":"2000-01-01 00:00:00"';
        $id = '"id":1';
        $next = '"_pointsToNextItems":true';
        $valid = "{{$date},{$id},{$next}}";

        $json = [
            'JSON でない' => 'abc',
            '中身が空' => '',
            '数値' => '1',
            '文字列' => '"abc"',
            'null' => 'null',
            '空のオブジェクト' => '{}',
            '配列' => "[{$valid}]",
            '日時が無い' => "{{$id},{$next}}",
            'ID が無い' => "{{$date},{$next}}",
            '向きが無い' => "{{$date},{$id}}",
            '余分な項目' => "{{$date},{$id},{$next},\"body\":\"a\"}",
            '項目名が違う' => "{{$date},\"messages.id\":1,{$next}}",
            '日時が文章' => "{\"created_at\":\"abc\",{$id},{$next}}",
            '日時の形が違う' => "{\"created_at\":\"2000-01-01T00:00:00+09:00\",{$id},{$next}}",
            '実在しない日' => "{\"created_at\":\"2000-02-30 00:00:00\",{$id},{$next}}",
            '実在しない時刻' => "{\"created_at\":\"2000-01-01 24:00:00\",{$id},{$next}}",
            '日時の後ろに改行' => "{\"created_at\":\"2000-01-01 00:00:00\\n\",{$id},{$next}}",
            '日時の後ろに NUL' => "{\"created_at\":\"2000-01-01 00:00:00\\u0000\",{$id},{$next}}",
            '日時が数値' => "{\"created_at\":0,{$id},{$next}}",
            '日時が配列' => "{\"created_at\":[\"2000-01-01 00:00:00\"],{$id},{$next}}",
            'ID が配列' => "{{$date},\"id\":[1],{$next}}",
            'ID が文字列' => "{{$date},\"id\":\"1\",{$next}}",
            'ID が小数' => "{{$date},\"id\":1.5,{$next}}",
            'ID が大きすぎる' => "{{$date},\"id\":99999999999999999999,{$next}}",
            'ID が null' => "{{$date},\"id\":null,{$next}}",
            '向きが数値' => "{{$date},{$id},\"_pointsToNextItems\":1}",
            '向きが文字列' => "{{$date},{$id},\"_pointsToNextItems\":\"true\"}",
        ];

        return [
            'base64 でない' => ['cursor=!!!'],
            '正しい値に base64 でない文字が混ざる' => ['cursor=!'.self::encodeCursor($valid)],
            '空' => ['cursor='],
            '値が配列（cursor[]=）' => ['cursor[]='],
            '正しい値を配列で送る' => ['cursor[]='.self::encodeCursor($valid)],
            ...array_map(fn (string $value) => ['cursor='.self::encodeCursor($value)], $json),
        ];
    }

    // TP-7-08
    #[DataProvider('brokenCursors')]
    public function test_a_broken_cursor_returns_the_first_page(string $query): void
    {
        $this->messages(3);
        $first = $this->get($this->messagesPath())->assertOk()->assertJsonCount(3, 'data');

        $response = $this->get($this->messagesPath()."?{$query}")->assertOk();

        $this->assertSame($first->json(), $response->json());
    }

    // TP-7-08（上の土台の値は、形が正しければ効く。端の値でも失敗しない）
    public function test_a_well_formed_cursor_is_used_even_at_the_edges(): void
    {
        $ids = $this->messages(3);
        $cursor = fn (string $date, int $id) => $this->messagesPath().'?cursor='.self::encodeCursor(
            json_encode(['created_at' => $date, 'id' => $id, '_pointsToNextItems' => true])
        );

        $this->get($cursor('2000-01-01 00:00:00', 1))->assertOk()->assertJsonCount(0, 'data');
        $this->get($cursor('0001-01-01 00:00:00', PHP_INT_MIN))->assertOk()->assertJsonCount(0, 'data');

        $all = $this->get($cursor('9999-12-31 23:59:59', PHP_INT_MAX))->assertOk();

        $this->assertSame($ids, $all->json('data.*.id'));
    }

    /** @return array<string, array{string}> */
    public static function notFoundPaths(): array
    {
        return [
            '存在しないID' => ['/api/channels/999999/messages'],
            'プライベートチャンネルのID' => ['/api/channels/{private}/messages'],
            '数字でないもの' => ['/api/channels/abc/messages'],
            '数字で始まる文字' => ['/api/channels/{public}abc/messages'],
            '整数に収まらない数字' => ['/api/channels/99999999999999999999/messages'],
            '無いURL' => ['/api/no-such-url'],
            '1件のチャンネルのURL' => ['/api/channels/{public}'],
        ];
    }

    // TP-7-09・TP-7-11
    #[DataProvider('notFoundPaths')]
    public function test_every_not_found_has_the_same_body(string $path): void
    {
        // 例外の文言（モデルの名前や ID）が出る設定でも、同じ本文にする
        config(['app.debug' => true]);
        $path = strtr($path, ['{private}' => $this->privateChannel()->id, '{public}' => $this->channel->id]);

        $this->assertApiError(404, self::NOT_FOUND, $this->get($path));
        $this->assertApiError(404, self::NOT_FOUND, $this->callWithoutAccept('GET', $path));
        $this->assertApiError(404, self::NOT_FOUND, $this->getJson($path));
    }

    /** @return array<string, array{string}> */
    public static function writingMethods(): array
    {
        return [
            'POST' => ['POST'],
            'PUT' => ['PUT'],
            'PATCH' => ['PATCH'],
            'DELETE' => ['DELETE'],
        ];
    }

    // TP-7-10・TP-7-11
    #[DataProvider('writingMethods')]
    public function test_methods_other_than_get_are_not_allowed(string $method): void
    {
        $message = $this->message(['body' => '元の本文']);

        foreach ([self::CHANNELS, $this->messagesPath()] as $path) {
            $response = $this->callWithoutAccept($method, $path);

            $this->assertApiError(405, '{"message":"Method Not Allowed"}', $response);
            $this->assertSame('GET, HEAD', $response->headers->get('Allow'));
        }

        $this->assertDatabaseCount('channels', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertSame('元の本文', $message->fresh()->body);
    }

    // TP-7-11
    public function test_success_responses_are_json_without_an_accept_header(): void
    {
        foreach ([self::CHANNELS, $this->messagesPath()] as $path) {
            $this->callWithoutAccept('GET', $path)
                ->assertOk()
                ->assertHeader('Content-Type', 'application/json');
        }
    }

    // TP-7-12
    public function test_the_result_does_not_depend_on_who_is_logged_in(): void
    {
        $private = $this->privateChannel();
        $this->message([], $private);
        $this->message();

        $guestChannels = $this->get(self::CHANNELS)->assertOk()->json();
        $guestMessages = $this->get($this->messagesPath())->assertOk()->json();
        $this->assertApiError(404, self::NOT_FOUND, $this->get($this->messagesPath($private)));

        // 作成者でメンバーの人と、メンバーでない人
        foreach ([$this->me, $this->other] as $user) {
            $this->actingAs($user);

            $this->assertSame($guestChannels, $this->get(self::CHANNELS)->assertOk()->json());
            $this->assertSame($guestMessages, $this->get($this->messagesPath())->assertOk()->json());
            $this->assertApiError(404, self::NOT_FOUND, $this->get($this->messagesPath($private)));
        }
    }

    // TP-7-13
    public function test_times_are_returned_with_the_japanese_offset(): void
    {
        // 世界標準時の 2026-08-16 23:30 は、日本時間の翌日 08:30
        $this->travelTo(Carbon::parse('2026-08-16 23:30:00', 'UTC'));
        $channel = Channel::factory()->create(['creator_id' => $this->me->id]);
        $this->message([], $channel);

        $this->get(self::CHANNELS)->assertJsonPath('data.1.created_at', '2026-08-17T08:30:00+09:00');
        $this->get($this->messagesPath($channel))->assertJsonPath('data.0.created_at', '2026-08-17T08:30:00+09:00');
    }

    // TP-7-14（OPTIONS と HEAD の扱いは D-40）
    public function test_no_cors_header_is_returned(): void
    {
        $origin = ['Origin' => 'https://portal.example.test'];
        $preflight = [...$origin, 'Access-Control-Request-Method' => 'GET'];

        foreach ([self::CHANNELS, $this->messagesPath()] as $path) {
            $this->assertNoCorsHeader($this->get($path, $origin)->assertOk());

            $options = $this->options($path, [], $preflight)->assertOk();

            $this->assertSame('GET,HEAD', $options->headers->get('Allow'));
            $this->assertNoCorsHeader($options);
            $this->assertNoCorsHeader($this->call('HEAD', $path)->assertOk());
        }

        $this->assertNoCorsHeader($this->get('/api/no-such-url', $origin)->assertNotFound());
    }

    private function assertNoCorsHeader(TestResponse $response): void
    {
        $cors = array_filter(
            array_keys($response->headers->all()),
            fn (string $name) => str_starts_with($name, 'access-control-')
        );

        $this->assertSame([], array_values($cors));
    }

    // TP-7-15・TP-7-11
    public function test_the_sixty_first_call_from_one_ip_is_rejected(): void
    {
        $this->freezeTime();

        // 2つの API の呼び出しを、合わせて数える
        for ($i = 1; $i <= 60; $i++) {
            $this->get($i % 2 === 0 ? self::CHANNELS : $this->messagesPath())->assertOk();
        }

        $response = $this->callWithoutAccept('GET', self::CHANNELS);

        $this->assertApiError(429, '{"message":"Too Many Attempts."}', $response);
        $response->assertHeader('Retry-After', 60);

        // ログインしていても、IP で数える
        $this->actingAs($this->me)->get(self::CHANNELS)->assertTooManyRequests();

        // 別の IP は通る
        $this->call('GET', self::CHANNELS, server: ['REMOTE_ADDR' => '192.0.2.10'])->assertOk();
    }

    // TP-7-16
    public function test_a_channel_without_messages_gives_an_empty_list(): void
    {
        $this->get($this->messagesPath())->assertOk()->assertJsonPath('data', []);
    }

    // TP-7-16
    public function test_a_channel_with_only_deleted_messages_gives_an_empty_list(): void
    {
        Message::factory()->deleted()->count(3)->create(['channel_id' => $this->channel->id]);

        $this->get($this->messagesPath())
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.next_cursor', null);
    }

    // TP-7-11（500。behavior.md 3-1 #8）
    public function test_an_unexpected_failure_hides_the_details_even_in_debug_mode(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        DB::beforeExecuting(fn () => throw new RuntimeException('接続できません'));

        $this->assertApiError(500, '{"message":"Server Error"}', $this->callWithoutAccept('GET', self::CHANNELS));

        // 原因はログに残す
        Log::shouldHaveReceived('error')->once();
    }

    /** @return array<string, array{int, string}> */
    public static function otherStatuses(): array
    {
        return [
            '403 は HTTP の標準の言葉' => [403, 'Forbidden'],
            '503 は 500番台の言葉' => [503, 'Server Error'],
        ];
    }

    // permissions-api.md 3-4（本文は message だけで、例外の文言を使わない）
    #[DataProvider('otherStatuses')]
    public function test_other_statuses_do_not_use_the_exception_message(int $status, string $message): void
    {
        config(['app.debug' => true]);
        Log::spy();
        Route::get('/api/test-only-error', fn () => abort($status, '内部の事情'));

        $this->assertApiError($status, json_encode(['message' => $message]), $this->get('/api/test-only-error'));
    }

    // D-10
    public function test_the_unused_default_urls_are_gone(): void
    {
        $this->actingAs($this->me);

        $this->assertApiError(404, self::NOT_FOUND, $this->get('/api/user'));
        $this->get('/sanctum/csrf-cookie')->assertNotFound();
    }

    // D-14（画面のエラーは、変えない）
    public function test_not_found_pages_of_the_screens_are_still_html(): void
    {
        $this->actingAs($this->me);

        foreach (['/no-such-page', '/channels/999999'] as $path) {
            $this->get($path)
                ->assertNotFound()
                ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
                ->assertSee('Not Found');
        }
    }
}
