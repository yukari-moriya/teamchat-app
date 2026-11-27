<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 画面を通さずに、文字の欄へ配列を送ったとき。画面からは送れないが、直接送られても 500 にせず、
 * 入力エラーと同じく、戻った先の画面が開いて欄の下にエラーが出る（docs/design/behavior.md 3-1 の1）。
 * 返信欄は ThreadTest、メンバー追加の欄は MemberTest で確かめる。
 */
class ArrayInputTest extends TestCase
{
    use RefreshDatabase;

    private const ARRAY = ['配列'];

    private function xpath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent());
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    /** 戻った先の画面の、欄に入っている値 */
    private function fieldValue(DOMXPath $xpath, string $name): string
    {
        $field = $xpath->query("//*[(self::input or self::textarea) and @name=\"{$name}\"]")->item(0);

        return $field->nodeName === 'textarea' ? $field->textContent : $field->getAttribute('value');
    }

    private function channel(User $creator): Channel
    {
        return Channel::factory()->create(['name' => '開発', 'description' => '開発の相談', 'creator_id' => $creator->id]);
    }

    // ログインの欄の文言は Fortify の入力チェックのままなので、画面が開くことと、欄が空になることを確かめる
    public function test_login_page_opens_after_an_array_email(): void
    {
        $this->from('/login')->post('/login', ['email' => self::ARRAY, 'password' => 'password'])
            ->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertSame('', $this->fieldValue($this->xpath($this->get('/login')->assertOk()), 'email'));
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('registrationArrays')]
    public function test_registration_with_an_array_is_an_input_error(array $overrides, string $field, string $error): void
    {
        $this->from('/register')->post('/register', [
            'name' => '新井 次郎',
            'email' => 'arai@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            ...$overrides,
        ])->assertRedirect('/register')->assertSessionHasErrors([$field => $error]);

        $this->get('/register')->assertOk()->assertSee($error);
        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function registrationArrays(): array
    {
        // 配列の min:8 は要素の数を数えるので、8個以上でも確かめる
        $eight = array_fill(0, 8, 'a');

        return [
            '表示名' => [['name' => self::ARRAY], 'name', '表示名を入力してください'],
            'メールアドレス' => [['email' => self::ARRAY], 'email', 'メールアドレスを入力してください'],
            '要素が少ないパスワード' => [['password' => self::ARRAY, 'password_confirmation' => self::ARRAY], 'password', 'パスワードを入力してください'],
            '要素が8個のパスワード' => [['password' => $eight, 'password_confirmation' => $eight], 'password', 'パスワードを入力してください'],
        ];
    }

    #[DataProvider('channelFields')]
    public function test_channel_create_page_opens_after_an_array(string $field): void
    {
        $this->actingAs(User::factory()->create());

        $this->from('/channels/create')
            ->post('/channels', ['name' => '新しい場所', 'description' => '説明', 'visibility' => 'public', $field => self::ARRAY])
            ->assertRedirect('/channels/create')->assertSessionHasErrors($field);

        $this->assertSame('', $this->fieldValue($this->xpath($this->get('/channels/create')->assertOk()), $field));
        $this->assertSame(0, Channel::count());
    }

    // 編集の画面では、欄をいまの保存値に戻す
    #[DataProvider('channelFields')]
    public function test_channel_edit_page_opens_after_an_array(string $field): void
    {
        $me = User::factory()->create();
        $channel = $this->channel($me);
        $url = "/channels/{$channel->id}/edit";
        $this->actingAs($me);

        $this->from($url)->put("/channels/{$channel->id}", ['name' => '開発', 'description' => '説明', $field => self::ARRAY])
            ->assertRedirect($url)->assertSessionHasErrors($field);

        $this->assertSame($channel->{$field}, $this->fieldValue($this->xpath($this->get($url)->assertOk()), $field));
        $this->assertSame('開発の相談', $channel->refresh()->description);
    }

    /** @return array<string, array{string}> */
    public static function channelFields(): array
    {
        return ['チャンネル名' => ['name'], '説明' => ['description']];
    }

    public function test_delete_confirmation_opens_after_an_array(): void
    {
        $me = User::factory()->create();
        $channel = $this->channel($me);
        $url = route('channels.edit', ['channel' => $channel, 'confirm_delete' => 1]);
        $this->actingAs($me);

        $this->delete("/channels/{$channel->id}", ['confirm_name' => self::ARRAY])
            ->assertRedirect($url)->assertSessionHasErrors(['confirm_name' => 'チャンネル名が一致しません']);

        $this->assertSame('', $this->fieldValue($this->xpath($this->get($url)->assertOk()), 'confirm_name'));
        $this->assertModelExists($channel);
    }

    public function test_composer_opens_after_an_array(): void
    {
        $me = User::factory()->create();
        $url = "/channels/{$this->channel($me)->id}";
        $this->actingAs($me);

        $this->from($url)->post("{$url}/messages", ['body' => self::ARRAY])
            ->assertRedirect($url)->assertSessionHasErrors(['body' => 'メッセージを入力してください']);

        $xpath = $this->xpath($this->get($url)->assertOk()->assertSee('メッセージを入力してください'));
        $this->assertSame('', $this->fieldValue($xpath, 'body'));
        $this->assertSame(0, Message::count());
    }

    // 編集欄は、いまの本文に戻す
    public function test_edit_field_opens_after_an_array(): void
    {
        $me = User::factory()->create();
        $channel = $this->channel($me);
        $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $me->id, 'body' => 'いまの本文']);
        $url = "/channels/{$channel->id}?edit={$message->id}";
        $this->actingAs($me);

        $this->put("/channels/{$channel->id}/messages/{$message->id}", ['edit_body' => self::ARRAY])
            ->assertSessionHasErrors(['edit_body' => 'メッセージを入力してください']);

        $this->assertSame('いまの本文', $this->fieldValue($this->xpath($this->get($url)->assertOk()), 'edit_body'));
        $this->assertSame('いまの本文', $message->refresh()->body);
    }

    // 前回の入力値は次の画面にまとめて持ち越すので、送り先と違う欄に配列を足されても、その画面が開く
    public function test_thread_opens_when_an_array_for_another_field_is_added_to_a_reply(): void
    {
        $me = User::factory()->create();
        $channel = $this->channel($me);
        $origin = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $me->id]);
        $url = "/channels/{$channel->id}?thread={$origin->id}";
        $this->actingAs($me);

        $this->from($url)->post("/channels/{$channel->id}/messages/{$origin->id}/replies", ['reply_body' => '', 'body' => self::ARRAY])
            ->assertRedirect($url);

        $this->assertSame('', $this->fieldValue($this->xpath($this->get($url)->assertOk()), 'body'));
    }

    // 入力を空にして送ったときは、保存値に戻さず、空のまま出す（欄が空になる扱いは変えない）
    public function test_emptied_field_stays_empty_after_an_error(): void
    {
        $me = User::factory()->create();
        $channel = $this->channel($me);
        $url = "/channels/{$channel->id}/edit";
        $this->actingAs($me);

        $this->from($url)->put("/channels/{$channel->id}", ['name' => '', 'description' => ''])->assertRedirect($url);

        $xpath = $this->xpath($this->get($url)->assertOk());
        $this->assertSame('', $this->fieldValue($xpath, 'name'));
        $this->assertSame('', $this->fieldValue($xpath, 'description'));
    }
}
