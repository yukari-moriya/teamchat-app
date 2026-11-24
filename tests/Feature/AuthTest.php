<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 認証（実装単位 1）。観点は docs/design/acceptance.md の 2-1（TP-1-01〜18）。
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const WRONG = 'メールアドレスまたはパスワードが正しくありません';

    private const LOCKED = 'ログインの試行回数が多すぎるため、しばらく待ってからもう一度ログインしてください';

    /** @param array<string, mixed> $overrides */
    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => '新井 次郎',
            'email' => 'arai@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
        ], $overrides);
    }

    private function register(array $overrides = [])
    {
        return $this->from('/register')->post('/register', $this->registration($overrides));
    }

    private function login(string $email, string $password)
    {
        return $this->from('/login')->post('/login', ['email' => $email, 'password' => $password]);
    }

    private function emailOfLength(int $length): string
    {
        // 64文字の @ より前 + 短いラベルをつないだ @ より後。
        // 63文字のラベルを重ねると、Laravel の email の判定が長さの上限より前に落とすので、短いラベルにする
        $labels = [];
        $rest = $length - 65;
        while ($rest > 0) {
            $size = $rest > 41 ? 20 : $rest;
            $labels[] = str_repeat('b', $size);
            $rest -= $size + 1;
        }

        return str_repeat('a', 64).'@'.implode('.', $labels);
    }

    // TP-1-01
    public function test_registering_logs_the_user_in_and_goes_to_channels(): void
    {
        $this->register()->assertRedirect('/channels');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['name' => '新井 次郎', 'email' => 'arai@example.com']);
        $this->get('/channels')->assertOk()->assertSee('新井 次郎');
    }

    // 登録のあとは、開こうとしていたページではなく /channels（Q-04）
    public function test_registering_ignores_the_intended_url(): void
    {
        Route::middleware(['web', 'auth'])->get('/__intended', fn () => 'ok');
        $this->get('/__intended')->assertRedirect('/login');

        $this->register()->assertRedirect('/channels');
    }

    // TP-1-02
    /** @dataProvider emptyFields */
    public function test_empty_fields_show_an_error_and_do_not_register(string $field, string $message): void
    {
        $this->register([$field => ''])->assertRedirect('/register')->assertSessionHasErrors([$field => $message]);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public static function emptyFields(): array
    {
        return [
            '表示名' => ['name', '表示名を入力してください'],
            'メールアドレス' => ['email', 'メールアドレスを入力してください'],
            'パスワード' => ['password', 'パスワードを入力してください'],
            '確認用' => ['password_confirmation', 'パスワード（確認用）を入力してください'],
        ];
    }

    // TP-1-03
    public function test_name_is_accepted_at_30_characters_and_rejected_at_31(): void
    {
        $this->register(['name' => str_repeat('あ', 31)])
            ->assertSessionHasErrors(['name' => '表示名は30文字以内で入力してください']);
        $this->assertDatabaseCount('users', 0);

        $this->register(['name' => str_repeat('あ', 30)])->assertRedirect('/channels');
        $this->assertDatabaseCount('users', 1);
    }

    // TP-1-04
    public function test_email_is_accepted_at_254_characters_and_rejected_at_255(): void
    {
        $this->assertSame(255, strlen($this->emailOfLength(255)));
        $this->register(['email' => $this->emailOfLength(255)])
            ->assertSessionHasErrors(['email' => 'メールアドレスは254文字以内で入力してください']);
        $this->assertDatabaseCount('users', 0);

        $this->assertSame(254, strlen($this->emailOfLength(254)));
        $this->register(['email' => $this->emailOfLength(254)])->assertRedirect('/channels');
        $this->assertDatabaseCount('users', 1);
    }

    // TP-1-05
    public function test_password_is_accepted_at_8_characters_and_rejected_at_7(): void
    {
        $this->register(['password' => '1234567', 'password_confirmation' => '1234567'])
            ->assertSessionHasErrors(['password' => 'パスワードは8文字以上で入力してください']);
        $this->assertDatabaseCount('users', 0);

        // 前後の空白は取り除かない（取り除くと6文字になって通らない）
        $this->register(['password' => '  abcdef', 'password_confirmation' => '  abcdef'])->assertRedirect('/channels');
        $this->assertTrue(Hash::check('  abcdef', User::first()->password));
    }

    // TP-1-06
    public function test_invalid_email_and_mismatched_confirmation_are_rejected(): void
    {
        $this->register(['email' => 'arai-at-example'])
            ->assertSessionHasErrors(['email' => 'メールアドレスの形式で入力してください']);

        $this->register(['password_confirmation' => 'password2'])
            ->assertSessionHasErrors(['password_confirmation' => 'パスワード（確認用）がパスワードと一致しません']);

        $this->assertDatabaseCount('users', 0);
    }

    // TP-1-07
    public function test_duplicates_are_found_through_the_normalized_copy(): void
    {
        User::factory()->create(['name' => 'Taro Sato', 'email' => 'sato@example.com']);

        // 英字の大小・全角半角（空白を含む）だけが違う表示名
        $this->register(['name' => 'ＴＡＲＯ　ＳＡＴＯ'])
            ->assertSessionHasErrors(['name' => 'その表示名はすでに使われています']);
        // 英字の大小だけが違うメールアドレス（形式のチェックは、そろえる前の入力に当てる。D-22）
        $this->register(['email' => 'SATO@Example.com'])
            ->assertSessionHasErrors(['email' => 'そのメールアドレスはすでに使われています']);
        $this->assertDatabaseCount('users', 1);

        // ひらがなとカタカナは別のもの
        User::factory()->create(['name' => 'さとう']);
        $this->register(['name' => 'サトウ'])->assertRedirect('/channels');
        $this->assertDatabaseCount('users', 3);
    }

    // TP-1-08
    public function test_blank_name_is_empty_and_the_limit_counts_after_trimming(): void
    {
        $this->register(['name' => " \n　 "])->assertSessionHasErrors(['name' => '表示名を入力してください']);

        $this->register(['name' => '　 '.str_repeat('あ', 31).' '])
            ->assertSessionHasErrors(['name' => '表示名は30文字以内で入力してください']);

        $this->register(['name' => '　 '.str_repeat('あ', 30).' '])->assertRedirect('/channels');
        $this->assertSame(str_repeat('あ', 30), User::first()->name);
    }

    // TP-1-09
    public function test_values_are_kept_after_an_error_except_passwords(): void
    {
        $this->register(['name' => '残る名前', 'email' => 'nokoru@example.com', 'password' => 'secret-pass', 'password_confirmation' => 'other-pass'])
            ->assertRedirect('/register');

        $this->get('/register')
            ->assertOk()
            ->assertSee('value="残る名前"', false)
            ->assertSee('value="nokoru@example.com"', false)
            ->assertDontSee('secret-pass')
            ->assertDontSee('other-pass');
    }

    // 欄の下に出すエラーは1つだけ（上から順に見て、最初に引っかかったもの）
    public function test_only_the_first_error_of_a_field_is_reported(): void
    {
        $this->register(['name' => str_repeat('＠', 31)])->assertSessionHasErrors('name');

        $this->assertSame(['表示名は30文字以内で入力してください'], session('errors')->get('name'));
    }

    // TP-1-10
    public function test_login_works_and_ignores_case_and_width_of_the_email(): void
    {
        User::factory()->create(['email' => 'sato@example.com', 'password' => 'password']);

        foreach (['sato@example.com', 'SATO@EXAMPLE.COM', 'ｓａｔｏ＠ｅｘａｍｐｌｅ．ｃｏｍ', ' sato@example.com '] as $email) {
            $this->login($email, 'password')->assertRedirect('/channels');
            $this->assertAuthenticated();
            $this->post('/logout');
        }
    }

    // TP-1-11
    public function test_wrong_credentials_give_the_same_message_under_the_password_field(): void
    {
        User::factory()->create(['email' => 'sato@example.com', 'password' => 'password']);

        $this->login('nobody@example.com', 'password')
            ->assertRedirect('/login')->assertSessionHasErrors(['password' => self::WRONG]);
        $this->login('sato@example.com', 'wrong-password')
            ->assertRedirect('/login')->assertSessionHasErrors(['password' => self::WRONG]);
        $this->assertGuest();

        $this->login('', 'password')->assertSessionHasErrors(['email' => 'メールアドレスを入力してください']);
        $this->login('sato@example.com', '')->assertSessionHasErrors(['password' => 'パスワードを入力してください']);
    }

    // AC-1-2: 画面でも、メールアドレスの値が残り、パスワードの欄の下にエラーが出る
    public function test_the_login_page_shows_the_error_under_the_password_field(): void
    {
        User::factory()->create(['email' => 'sato@example.com', 'password' => 'password']);

        $this->login('sato@example.com', 'wrong')->assertRedirect('/login');

        $this->get('/login')->assertSee('value="sato@example.com"', false)->assertSee(self::WRONG);
    }

    // TP-1-12
    public function test_login_goes_to_the_intended_page_or_channels(): void
    {
        Route::middleware(['web', 'auth'])->get('/__intended', fn () => 'ok');
        User::factory()->create(['email' => 'sato@example.com', 'password' => 'password']);

        $this->get('/__intended')->assertRedirect('/login');
        $this->login('sato@example.com', 'password')->assertRedirect(url('/__intended'));

        $this->post('/logout');
        $this->login('sato@example.com', 'password')->assertRedirect('/channels');
    }

    // TP-1-13
    public function test_guests_are_sent_to_login_from_screens(): void
    {
        Route::middleware(['web', 'auth'])->get('/__screen', fn () => 'ok');

        $this->get('/channels')->assertRedirect('/login');
        $this->get('/__screen')->assertRedirect('/login');
    }

    // TP-1-14
    public function test_the_root_redirects_by_login_state(): void
    {
        $this->get('/')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())->get('/')->assertRedirect('/channels');
    }

    // TP-1-15
    public function test_logged_in_users_are_sent_to_channels_from_login_and_register(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/login')->assertRedirect('/channels');
        $this->get('/register')->assertRedirect('/channels');
        $this->post('/login', ['email' => 'x@example.com', 'password' => 'x'])->assertRedirect('/channels');
        $this->post('/register', $this->registration())->assertRedirect('/channels');
        $this->assertDatabaseCount('users', 1);
    }

    // TP-1-16
    public function test_logout_ends_the_session_and_goes_to_login(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->get('/channels')->assertRedirect('/login');
    }

    // ログアウトは、ログイン中の人だけ
    public function test_logout_requires_login(): void
    {
        $this->post('/logout')->assertRedirect('/login');
    }

    // TP-1-17
    public function test_at_signs_are_not_allowed_in_the_name(): void
    {
        foreach (['佐藤@太郎', '佐藤＠太郎', '@', '＠'] as $name) {
            $this->register(['name' => $name])->assertSessionHasErrors(['name' => '表示名に「@」は使えません']);
        }

        $this->assertDatabaseCount('users', 0);
    }

    // TP-1-18
    public function test_login_is_limited_to_5_tries_a_minute_per_email_and_ip(): void
    {
        User::factory()->create(['email' => 'sato@example.com', 'password' => 'password']);
        User::factory()->create(['email' => 'suzuki@example.com', 'password' => 'password']);

        // 5回までは試せる。メールアドレスの大小・全角半角が違っても、同じ組として数える
        foreach (['sato@example.com', 'SATO@example.com', 'ｓａｔｏ＠example.com', 'sato@example.com', 'Sato@Example.com'] as $email) {
            $this->login($email, 'wrong')->assertSessionHasErrors(['password' => self::WRONG]);
        }

        // 6回目は、正しいパスワードでも試行回数のエラーになる
        $this->login('sato@example.com', 'password')->assertRedirect('/login')
            ->assertSessionHasErrors(['password' => self::LOCKED]);
        $this->assertGuest();

        // ほかのメールアドレスには影響しない
        $this->login('suzuki@example.com', 'password')->assertRedirect('/channels');
        $this->post('/logout');

        // 1分たつと、また試せる
        $this->travel(61)->seconds();
        $this->login('sato@example.com', 'password')->assertRedirect('/channels');
    }

    // D-30: 初期データのアカウント3つ（acceptance.md 0-2）
    public function test_seeded_accounts_can_log_in(): void
    {
        $this->seed(UserSeeder::class);
        $this->seed(UserSeeder::class); // 2回入れても増えない

        $this->assertDatabaseCount('users', 3);
        foreach (['sato@example.com', 'suzuki@example.com', 'takahashi@example.com'] as $email) {
            $this->login($email, 'password')->assertRedirect('/channels');
            $this->post('/logout');
        }
        $this->assertSame('佐藤 太郎', User::where('email', 'sato@example.com')->value('name'));
    }
}
