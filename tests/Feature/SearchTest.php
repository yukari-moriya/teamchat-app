<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Support\KeywordHighlighter;
use App\Support\TextNormalizer;
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
 * メッセージ検索（実装単位 6）。観点は docs/design/acceptance.md の 2-6（TP-6-01〜15）。
 * ほかの実装単位から回ってきた、TP-3-06・TP-3-07 の検索の部分と、TP-4-07 の「検索に出る・出ない」もここで確かめる。
 * 次の部分は、ブラウザで確かめる。
 * TP-6-04 の「その返信の位置へ移る」と AC-6-4 の、画面が実際に動くこと（ここでは、リンクの URL と移る先の行を確かめる）。
 * 改行や押せない側の見た目（CSS）。
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    private const ROW = '//div[@class="rows"]/a[@class="row"]';

    private const PAGER = '//nav[@class="pager"]';

    private User $me;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['name' => '佐藤 太郎']);
        $this->channel = Channel::factory()->create(['name' => '開発', 'creator_id' => $this->me->id]);
    }

    /** @param array<string, mixed> $attributes */
    private function message(string $body, ?Channel $channel = null, array $attributes = []): Message
    {
        return Message::factory()->create([
            'channel_id' => ($channel ?? $this->channel)->id,
            'user_id' => $this->me->id,
            'body' => $body,
            ...$attributes,
        ]);
    }

    /**
     * 同じキーワードを含むメッセージを、1分ずつ新しくして作る。
     *
     * @return list<Message> 古い順
     */
    private function messages(int $count, string $body = '議事録'): array
    {
        return array_map(
            fn (int $i) => $this->message("{$body} {$i}", attributes: ['created_at' => now()->subHours(2)->addMinutes($i)]),
            range(1, $count)
        );
    }

    /** 自分がメンバーでないプライベートチャンネル */
    private function hiddenChannel(): Channel
    {
        return Channel::factory()->private()->create(['name' => '秘密の相談']);
    }

    private function search(string $keyword, ?int $page = null, ?User $user = null): TestResponse
    {
        $query = ['q' => $keyword, ...($page === null ? [] : ['page' => $page])];

        return $this->actingAs($user ?? $this->me)->get('/search?'.http_build_query($query));
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

    /**
     * 結果の行の、チャンネル・区切り・投稿者・日時の文字。画面に出るとおりに、続く空白と改行は空白1つにする。
     *
     * @return list<string>
     */
    private function metaTexts(DOMXPath $xpath, string $query): array
    {
        return array_map(fn (string $text) => preg_replace('/\s+/', ' ', $text), $this->texts($xpath, $query));
    }

    /**
     * 結果の本文の文字（改行と空白もそのまま）を、出ている順に。
     *
     * @return list<string>
     */
    private function bodies(DOMXPath $xpath): array
    {
        $bodies = [];
        foreach ($xpath->query(self::ROW.'//div[contains(@class, "result__body")]') as $node) {
            $bodies[] = $node->textContent;
        }

        return $bodies;
    }

    /** @return list<string> 結果の行のリンク先を、出ている順に */
    private function links(DOMXPath $xpath): array
    {
        return array_map(
            fn (DOMElement $row) => $row->getAttribute('href'),
            iterator_to_array($xpath->query(self::ROW), false)
        );
    }

    /** 件数の行。出ていなければ null */
    private function countLine(DOMXPath $xpath): ?string
    {
        return $this->texts($xpath, '//p[contains(@class, "result-count")]')[0] ?? null;
    }

    /** SC-09 のキーワードの欄の値（上部バーの欄ではない） */
    private function keywordValue(DOMXPath $xpath): string
    {
        return $xpath->query('//div[@class="page "]//input[@name="q"]')->item(0)->getAttribute('value');
    }

    /** キーワードの欄の下のエラー。出ていなければ null */
    private function keywordError(DOMXPath $xpath): ?string
    {
        return $this->texts($xpath, '//div[contains(@class, "is-error") and .//input[@name="q"]]/span[@class="err"]')[0] ?? null;
    }

    // TP-6-01
    public function test_searches_only_message_bodies_by_partial_match(): void
    {
        $this->me->update(['name' => '議事録係']);
        $named = Channel::factory()->create(['name' => '議事録', 'description' => '議事録の置き場']);
        $this->message('本文には無い', $named);
        $this->message('先週の議事録を共有します');

        $xpath = $this->xpath($this->search('議事録')->assertOk());

        $this->assertSame(['先週の議事録を共有します'], $this->bodies($xpath));
        $this->assertSame('「議事録」の検索結果　1件', $this->countLine($xpath));
    }

    // TP-6-02
    #[DataProvider('comparisons')]
    public function test_comparison_rules(string $body, string $keyword, bool $matches): void
    {
        $this->message($body);

        $this->assertSame($matches ? [$body] : [], $this->bodies($this->xpath($this->search($keyword)->assertOk())));
    }

    /** @return array<string, array{string, string, bool}> */
    public static function comparisons(): array
    {
        return [
            '英字の大文字を小文字で' => ['Release の予定', 'release', true],
            '英字の小文字を大文字で' => ['release の予定', 'RELEASE', true],
            '全角の英数字を半角で' => ['ＡＢＣ１２３', 'abc123', true],
            '半角の英数字を全角で' => ['abc123', 'ＡＢＣ１２３', true],
            '半角カナを全角で' => ['ﾘﾘｰｽ手順', 'リリース', true],
            '全角カナを半角で' => ['リリース手順', 'ﾘﾘｰｽ', true],
            '濁点の付く半角カナ' => ['ｶﾞｲﾄﾞ', 'ガイド', true],
            '半角のかぎかっこ' => ['「重要」', '｢重要｣', true],
            '範囲の中の全角の波線' => ['1～2', '1~2', true],
            'ひらがなをカタカナで' => ['りんご', 'リンゴ', false],
            'カタカナをひらがなで' => ['リンゴ', 'りんご', false],
            '濁点の無い本文を濁点ありで' => ['ハス', 'バス', false],
            '濁点のある本文を濁点なしで' => ['バス', 'ハス', false],
            '半濁点の有無' => ['パン', 'ハン', false],
            '範囲の外の波線' => ['1〜2', '1~2', false],
        ];
    }

    // TP-6-03
    #[DataProvider('separators')]
    public function test_all_keywords_must_be_included(string $separator): void
    {
        $this->message('予定と会議室の両方');
        $this->message('予定だけ');
        $this->message('会議室だけ');

        $xpath = $this->xpath($this->search("会議室{$separator}予定")->assertOk());

        $this->assertSame(['予定と会議室の両方'], $this->bodies($xpath));
        $this->assertSame("「会議室{$separator}予定」の検索結果　1件", $this->countLine($xpath));
    }

    /** @return array<string, array{string}> */
    public static function separators(): array
    {
        return [
            '半角の空白' => [' '],
            '全角の空白' => ['　'],
            '続いた空白' => [" 　 \t"],
        ];
    }

    // 結果の行の項目（screens.md 3-8 の No 5・6）
    public function test_row_shows_channel_author_and_time(): void
    {
        $private = Channel::factory()->private()->create(['name' => '採用', 'creator_id' => $this->me->id]);
        $this->message('議事録A', attributes: ['created_at' => '2026-08-17 09:12:00', 'is_edited' => true]);
        $this->message('議事録B', $private, ['created_at' => '2026-08-12 11:20:00']);

        $xpath = $this->xpath($this->search('議事録')->assertOk());

        $this->assertSame(['# 開発', '·', '佐藤 太郎', '·', '2026/08/17 09:12'], $this->metaTexts($xpath, '('.self::ROW.')[1]//div[@class="result__meta"]/span'));
        $this->assertSame(1, $xpath->query('('.self::ROW.')[2]//span[@class="result__ch"]//*[local-name()="svg" and @aria-label="鍵"]')->length);
        $this->assertSame(['採用'], $this->metaTexts($xpath, '('.self::ROW.')[2]//span[@class="result__ch"]'));
        // 「編集済み」は出さない
        $this->assertSame(['議事録A', '議事録B'], $this->bodies($xpath));
    }

    // TP-6-04
    public function test_rows_link_to_the_message_and_replies_open_the_thread(): void
    {
        $origin = $this->message('元のメッセージ 議事録', attributes: ['created_at' => '2026-08-17 09:00:00']);
        $reply = Message::factory()->replyTo($origin)->create([
            'user_id' => $this->me->id, 'body' => '返信の議事録', 'created_at' => '2026-08-17 10:00:00',
        ]);

        $links = $this->links($this->xpath($this->search('議事録')->assertOk()));

        $base = route('channels.show', $this->channel);
        $this->assertSame([
            "{$base}?thread={$origin->id}#reply-{$reply->id}",
            "{$base}#message-{$origin->id}",
        ], $links);

        $thread = $this->xpath($this->get(strstr($links[0], '#', true))->assertOk());
        $this->assertSame(1, $thread->query("//*[@id=\"reply-{$reply->id}\"]")->length);
        $channel = $this->xpath($this->get(strstr($links[1], '#', true))->assertOk());
        $this->assertSame(1, $channel->query("//*[@id=\"message-{$origin->id}\"]")->length);
    }

    // TP-6-04: 元のメッセージが削除されていても、返信は出て、スレッドが開く（R-06）
    public function test_reply_to_a_deleted_message_is_found(): void
    {
        $origin = Message::factory()->deleted()->create(['channel_id' => $this->channel->id, 'body' => '消した議事録']);
        $reply = Message::factory()->replyTo($origin)->create(['body' => '返信の議事録']);

        $xpath = $this->xpath($this->search('議事録')->assertOk());

        $this->assertSame(['返信の議事録'], $this->bodies($xpath));
        $this->get(strstr($this->links($xpath)[0], '#', true))->assertOk()->assertSee("reply-{$reply->id}");
    }

    // TP-6-05・TP-6-07。出せない1件を含めると21件になる形で、行・件数・ページ送りのどれにも出ないことを確かめる
    #[DataProvider('unlistables')]
    public function test_unlistable_messages_appear_nowhere(string $kind): void
    {
        $this->messages(20);
        // いちばん新しくして、混ざれば1ページ目に出るようにする
        $attributes = ['created_at' => now()];

        match ($kind) {
            'hidden' => $this->message('出ないはずの議事録', $this->hiddenChannel(), $attributes),
            'hidden reply' => Message::factory()
                ->replyTo($this->message('元', $this->hiddenChannel()))
                ->create(['body' => '出ないはずの議事録', ...$attributes]),
            'deleted' => $this->message('出ないはずの議事録', attributes: ['deleted_at' => now(), ...$attributes]),
            'deleted reply' => Message::factory()->deleted()
                ->replyTo($this->message('元'))
                ->create(['body' => '出ないはずの議事録', ...$attributes]),
        };

        $response = $this->search('議事録')->assertOk()->assertDontSee('出ないはずの');
        $xpath = $this->xpath($response);

        $this->assertCount(20, $this->bodies($xpath));
        $this->assertSame('「議事録」の検索結果　20件', $this->countLine($xpath));
        $this->assertSame(0, $xpath->query(self::PAGER)->length);
        $this->search('議事録', 2)->assertRedirect(route('search', ['q' => '議事録', 'page' => 1]));
    }

    /** @return array<string, array{string}> */
    public static function unlistables(): array
    {
        return [
            '見られないチャンネルのメッセージ' => ['hidden'],
            '見られないチャンネルの返信' => ['hidden reply'],
            '削除済みのメッセージ' => ['deleted'],
            '削除済みの返信' => ['deleted reply'],
        ];
    }

    // TP-6-05: 見られない内容しか当たらなければ、0件と同じ表示
    public function test_only_hidden_matches_look_like_no_results(): void
    {
        $this->message('議事録', $this->hiddenChannel());

        $response = $this->search('議事録')->assertOk()->assertDontSee('秘密の相談');

        $this->assertSame('「議事録」の検索結果　0件', $this->countLine($this->xpath($response)));
    }

    // AC-6-3 の流れ（TP-6-07）
    public function test_message_disappears_after_it_is_deleted(): void
    {
        $message = $this->message('ukeire0603 の件');
        $this->assertSame(['ukeire0603 の件'], $this->bodies($this->xpath($this->search('ukeire0603'))));

        $this->delete("/channels/{$this->channel->id}/messages/{$message->id}")->assertRedirect();

        $xpath = $this->xpath($this->search('ukeire0603')->assertOk()->assertDontSee('の件'));
        $this->assertSame(['「ukeire0603」に一致するメッセージはありません'], $this->texts($xpath, '//div[@class="empty__title"]'));
    }

    // TP-6-06・TP-3-06・TP-3-07
    public function test_results_follow_membership(): void
    {
        $member = User::factory()->create(['name' => '高橋 健']);
        $private = Channel::factory()->private()->create(['name' => '採用', 'creator_id' => $this->me->id]);
        $this->message('追加される前の議事録', $private);

        $this->assertSame([], $this->bodies($this->xpath($this->search('議事録', user: $member)->assertOk())));

        $this->actingAs($this->me)
            ->post("/channels/{$private->id}/members", ['member' => '高橋 健'])->assertSessionHasNoErrors();
        $this->assertSame(['追加される前の議事録'], $this->bodies($this->xpath($this->search('議事録', user: $member))));

        // 自分が書いたメッセージも、外されたら出ない
        $this->message('本人が書いた議事録', $private, ['user_id' => $member->id]);
        $this->actingAs($this->me)->delete("/channels/{$private->id}/members/{$member->id}")->assertRedirect();

        $xpath = $this->xpath($this->search('議事録', user: $member)->assertOk());
        $this->assertSame([], $this->bodies($xpath));
        $this->assertSame('「議事録」の検索結果　0件', $this->countLine($xpath));
        $this->assertCount(2, $this->bodies($this->xpath($this->search('議事録'))));
    }

    // TP-4-07
    public function test_edited_message_is_found_by_the_new_words_only(): void
    {
        $message = $this->message('直す前の語');

        $this->actingAs($this->me)
            ->put("/channels/{$this->channel->id}/messages/{$message->id}", ['edit_body' => 'あとの言葉'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['あとの言葉'], $this->bodies($this->xpath($this->search('言葉'))));
        $this->assertSame([], $this->bodies($this->xpath($this->search('直す前'))));
    }

    // TP-6-08
    public function test_twenty_results_have_no_pager(): void
    {
        $this->messages(20);

        $xpath = $this->xpath($this->search('議事録')->assertOk());

        $this->assertCount(20, $this->bodies($xpath));
        $this->assertSame('「議事録」の検索結果　20件', $this->countLine($xpath));
        $this->assertSame(0, $xpath->query(self::PAGER)->length);
    }

    // TP-6-08
    public function test_twenty_one_results_are_paged_from_newest(): void
    {
        $this->messages(21);

        $first = $this->xpath($this->actingAs($this->me)->get('/search?q='.urlencode('議事録').'&foo=bar')->assertOk());

        $this->assertSame(array_map(fn (int $i) => "議事録 {$i}", range(21, 2)), $this->bodies($first));
        $this->assertSame('「議事録」の検索結果　21件', $this->countLine($first));
        $this->assertSame(['前のページ', '次のページ'], $this->texts($first, self::PAGER.'/*'));
        $this->assertSame(['前のページ'], $this->texts($first, self::PAGER.'/span[contains(@class, "is-disabled")]'));
        $this->assertSame(['次のページ'], $this->texts($first, self::PAGER.'/a'));
        // リンクに付けるのは q と page だけ
        $next = $first->query(self::PAGER.'/a')->item(0)->getAttribute('href');
        $this->assertSame(route('search', ['q' => '議事録', 'page' => 2]), $next);

        $second = $this->xpath($this->get($next)->assertOk());

        $this->assertSame(['議事録 1'], $this->bodies($second));
        $this->assertSame('「議事録」の検索結果　21件', $this->countLine($second));
        $this->assertSame('議事録', $this->keywordValue($second));
        $this->assertSame(['次のページ'], $this->texts($second, self::PAGER.'/span[contains(@class, "is-disabled")]'));
        $this->assertSame(['前のページ'], $this->texts($second, self::PAGER.'/a'));
        $this->assertSame(
            route('search', ['q' => '議事録', 'page' => 1]),
            $second->query(self::PAGER.'/a')->item(0)->getAttribute('href')
        );
    }

    // TP-6-08: 投稿日時が同じなら ID の小さい順。ページの境目でも、重ならず抜けない（D-41）
    public function test_same_time_is_ordered_by_smaller_id(): void
    {
        $newer = $this->message('議事録 新', attributes: ['created_at' => '2026-08-17 10:00:00']);
        $same = array_map(
            fn () => $this->message('議事録 同時', attributes: ['created_at' => '2026-08-17 09:00:00']),
            range(1, 21)
        );
        $older = $this->message('議事録 古', attributes: ['created_at' => '2026-08-17 08:00:00']);

        $links = [
            ...$this->links($this->xpath($this->search('議事録'))),
            ...$this->links($this->xpath($this->search('議事録', 2))),
        ];

        $this->assertSame(array_map(fn (Message $m) => $m->url(), [$newer, ...$same, $older]), $links);
    }

    // TP-6-09
    #[DataProvider('urlsWithoutKeyword')]
    public function test_without_a_keyword_only_the_form_is_shown(string $url): void
    {
        $this->message('議事録');

        $xpath = $this->xpath($this->actingAs($this->me)->get($url)->assertOk());

        $this->assertSame(['メッセージを検索'], $this->texts($xpath, '//h1'));
        $this->assertSame(['検索'], $this->texts($xpath, '//div[@class="page "]//button'));
        $this->assertSame('', $this->keywordValue($xpath));
        $this->assertNull($this->keywordError($xpath));
        $this->assertNull($this->countLine($xpath));
        $this->assertSame(0, $xpath->query('//div[@class="rows" or contains(@class, "empty")]')->length);
    }

    /** @return array<string, array{string}> */
    public static function urlsWithoutKeyword(): array
    {
        return [
            'クエリなし' => ['/search'],
            'page だけ' => ['/search?page=3'],
            'ほかのクエリだけ' => ['/search?foo=bar'],
        ];
    }

    // TP-6-09。配列は画面から送れないが、直接送られても、空と同じエラーにする
    #[DataProvider('blankKeywords')]
    public function test_blank_keyword_is_an_error_on_the_search_screen(string $query): void
    {
        $this->message('議事録');
        $this->actingAs($this->me);

        // 上部バーから、ほかの画面で送っても、SC-09 に出す
        $this->from("/channels/{$this->channel->id}")->get("/search?{$query}")
            ->assertRedirect(route('search'))
            ->assertSessionHasErrors(['q' => 'キーワードを入力してください']);

        $xpath = $this->xpath($this->get('/search')->assertOk());

        $this->assertSame('キーワードを入力してください', $this->keywordError($xpath));
        $this->assertSame('', $this->keywordValue($xpath));
        $this->assertNull($this->countLine($xpath));
        $this->assertSame([], $this->bodies($xpath));
    }

    /** @return array<string, array{string}> */
    public static function blankKeywords(): array
    {
        return [
            '空' => ['q='],
            '半角の空白だけ' => ['q=%20%20'],
            '全角の空白と改行だけ' => ['q='.rawurlencode("　\r\n　")],
            '配列' => ['q[]='.rawurlencode('議事録')],
            '不正な UTF-8 の配列' => ['q[]=%FF&page=2'],
        ];
    }

    // 前後の空白は取り除いて、欄と件数に出す
    public function test_keyword_is_trimmed(): void
    {
        $this->message('議事録');

        $xpath = $this->xpath($this->search('　 議事録　 ')->assertOk());

        $this->assertSame('議事録', $this->keywordValue($xpath));
        $this->assertSame('「議事録」の検索結果　1件', $this->countLine($xpath));
    }

    // 件数と欄には、そろえる前の、入れたキーワードを出す
    public function test_keyword_is_shown_as_entered(): void
    {
        $this->message('release');

        $xpath = $this->xpath($this->search('ＲＥＬＥＡＳＥ')->assertOk());

        $this->assertSame('ＲＥＬＥＡＳＥ', $this->keywordValue($xpath));
        $this->assertSame('「ＲＥＬＥＡＳＥ」の検索結果　1件', $this->countLine($xpath));
    }

    // TP-6-10
    public function test_no_results_shows_guidance(): void
    {
        $this->message('別の話');

        $xpath = $this->xpath($this->search('議事録')->assertOk());

        $this->assertSame('「議事録」の検索結果　0件', $this->countLine($xpath));
        $this->assertSame(['「議事録」に一致するメッセージはありません'], $this->texts($xpath, '//div[@class="empty__title"]'));
        $this->assertSame(['別のキーワードで試すか、キーワードを短くしてください。'], $this->texts($xpath, '//div[@class="empty__text"]'));
        $this->assertSame(0, $xpath->query('//div[@class="rows"]')->length);
        $this->assertSame(0, $xpath->query(self::PAGER)->length);
    }

    // そろえた結果が空になる入力（不正な UTF-8）で、全件を出さない
    public function test_invalid_utf8_matches_nothing(): void
    {
        $this->message('出ないはずの本文');

        $response = $this->actingAs($this->me)->get('/search?q=%FF')->assertOk()->assertDontSee('出ないはずの本文');

        $this->assertSame([], $this->bodies($this->xpath($response)));
        $location = $this->get('/search?q=%FF&page=3')->assertRedirect()->headers->get('Location');
        $this->assertSame([], $this->bodies($this->xpath($this->get($location)->assertOk())));
    }

    // TP-6-11
    #[DataProvider('highlights')]
    public function test_matched_parts_are_marked(string $body, string $keyword, array $marks): void
    {
        $this->message($body);

        $xpath = $this->xpath($this->search($keyword)->assertOk());

        $this->assertSame($marks, $this->texts($xpath, self::ROW.'//div[contains(@class, "result__body")]/mark'));
        $this->assertSame([$body], $this->bodies($xpath));
    }

    /** @return array<string, array{string, string, list<string>}> */
    public static function highlights(): array
    {
        return [
            '複数のキーワード' => ['リリースの予定を共有します', '予定 リリース', ['リリース', '予定']],
            '同じキーワードが2回' => ['予定と予定表', '予定', ['予定', '予定']],
            '濁点をまとめる半角カナの後ろ' => ['ﾃﾞｰﾀﾍﾞｰｽのﾘﾘｰｽ手順', 'リリース 手順', ['ﾘﾘｰｽ手順']],
            '濁点のある半角カナ自体' => ['新しいﾃﾞｰﾀﾍﾞｰｽです', 'データベース', ['ﾃﾞｰﾀﾍﾞｰｽ']],
            '半濁点のある半角カナ' => ['ﾊﾟﾝ屋のﾊﾟﾝ', 'パン', ['ﾊﾟﾝ', 'ﾊﾟﾝ']],
            '大文字と全角は本文のまま' => ['ＲｅｌｅａｓｅとRELEASE', 'release', ['Ｒｅｌｅａｓｅ', 'RELEASE']],
            '重なるキーワードは1つにまとめる' => ['xabcdx', 'abc bcd', ['abcd']],
            '隣り合うキーワードは1つにまとめる' => ['xabcdx', 'ab cd', ['abcd']],
            '同じキーワードが重なって当たる' => ['baaab', 'aa', ['aaa']],
            '含まれ合うキーワード' => ['リリース週', 'リリース リー', ['リリース']],
        ];
    }

    // 区切りごとにそろえてつないだ結果は、normalize() と合う。合わないと、本文の全体は当たらない
    #[DataProvider('foldSamples')]
    public function test_folding_by_units_agrees_with_normalize(string $body): void
    {
        $html = KeywordHighlighter::highlight($body, [TextNormalizer::normalize($body)])->toHtml();

        $this->assertSame('<mark>'.e($body).'</mark>', $html);
    }

    /** @return array<string, array{string}> */
    public static function foldSamples(): array
    {
        return [
            '全角の英数字と記号' => ['ＡＢＣ１２３！？％＿＼'],
            '全角の空白' => ['前　後'],
            '半角カナの濁点' => ['ｶﾞｷﾞｸﾞｳﾞｧｰ｡｢｣､･'],
            '半角カナの半濁点' => ['ﾊﾟﾋﾟﾌﾟﾍﾟﾎﾟ'],
            'まとまらない濁点・半濁点' => ['ｱﾞﾜﾞｦﾞｰﾟﾞﾟカﾞかﾟ'],
            '続いた濁点' => ['ｶﾞﾞﾟ'],
            '改行' => ["1行目ｶﾞ\n\n2行目"],
            '絵文字と結合文字' => ["👨‍👩‍👧 e\u{0301}"],
        ];
    }

    // TP-6-12
    public function test_full_body_is_shown_as_written(): void
    {
        $body = "1行目の議事録\n  2行目 <b>太字</b> <script>alert(1)</script> & &amp; \"引用\" 'x'\n\nhttps://example.com\n".str_repeat('あ', 800).'末尾';
        $this->message($body);

        $response = $this->search('議事録 <b> amp')->assertOk()
            ->assertDontSee('<script>alert', false)->assertDontSee('<b>太字', false);
        $xpath = $this->xpath($response);

        $this->assertSame([$body], $this->bodies($xpath));
        // amp は、書いた文字の「&amp;」と、URL の example の中の2か所
        $this->assertSame(['議事録', '<b>', 'amp', 'amp'], $this->texts($xpath, self::ROW.'//div[contains(@class, "result__body")]/mark'));
        // 本文の中の要素は、強調の mark だけ
        $this->assertSame(0, $xpath->query(self::ROW.'//div[contains(@class, "result__body")]//*[not(self::mark)]')->length);
    }

    // キーワードとチャンネル名も、書いた文字のまま出す
    public function test_keyword_and_channel_name_are_escaped(): void
    {
        $this->channel->update(['name' => '<i>開発</i>']);
        $this->message('"><script>alert(1)</script>');
        $keyword = '"><script>';

        $xpath = $this->xpath($this->search($keyword)->assertOk()->assertDontSee($keyword, false));

        $this->assertSame($keyword, $this->keywordValue($xpath));
        $this->assertSame("「{$keyword}」の検索結果　1件", $this->countLine($xpath));
        $this->assertSame(['# <i>開発</i>'], $this->metaTexts($xpath, self::ROW.'//span[@class="result__ch"]'));
        $this->assertSame(0, $xpath->query('//script | //i')->length);

        $empty = $this->xpath($this->search('<u>無い</u>')->assertOk());
        $this->assertSame(['「<u>無い</u>」に一致するメッセージはありません'], $this->texts($empty, '//div[@class="empty__title"]'));
    }

    // TP-6-13
    #[DataProvider('longKeywords')]
    public function test_keyword_has_no_length_limit(string $keyword, int $count): void
    {
        $this->message(str_repeat('あ', 1000));

        $xpath = $this->xpath($this->search($keyword)->assertOk());

        $this->assertNull($this->keywordError($xpath));
        $this->assertSame("「{$keyword}」の検索結果　{$count}件", $this->countLine($xpath));
    }

    /** @return array<string, array{string, int}> */
    public static function longKeywords(): array
    {
        return [
            '本文と同じ1000文字' => [str_repeat('あ', 1000), 1],
            '本文の上限より長い' => [str_repeat('あ', 1001), 0],
            'たくさんのキーワード' => [implode(' ', array_map(fn (int $i) => str_repeat('あ', $i), range(1, 100))), 1],
        ];
    }

    // TP-6-14
    #[DataProvider('wildcards')]
    public function test_like_wildcards_are_searched_as_characters(string $keyword, string $hit, string $miss): void
    {
        $this->message($hit);
        $this->message($miss);

        $this->assertSame([$hit], $this->bodies($this->xpath($this->search($keyword)->assertOk())));
    }

    /** @return array<string, array{string, string, string}> */
    public static function wildcards(): array
    {
        return [
            '%' => ['100%', '達成率は100%です', '1000円です'],
            '% だけ' => ['%', '50%', '50割'],
            '全角の％' => ['％', '50%', '50割'],
            '_' => ['a_c', 'a_c', 'abc'],
            '_ だけ' => ['_', 'snake_case', 'snake case'],
            '\\' => ['C:\\temp', 'C:\\temp を開く', 'C:temp を開く'],
            '\\ だけ' => ['\\', 'a\\b', 'a/b'],
            '\\ と %' => ['\\%', 'a\\%b', 'a%b'],
            '末尾の \\' => ['abc\\', 'abc\\', 'abc%'],
        ];
    }

    // TP-6-15
    public function test_page_after_the_last_moves_to_the_last_page(): void
    {
        $this->messages(21);

        foreach ([3, 100] as $page) {
            $this->search('議事録', $page)->assertRedirect(route('search', ['q' => '議事録', 'page' => 2]));
        }

        $this->assertSame(['議事録 1'], $this->bodies($this->xpath($this->search('議事録', 2)->assertOk())));
    }

    // TP-6-15
    public function test_page_without_results_moves_to_the_first_page(): void
    {
        $url = route('search', ['q' => '議事録', 'page' => 1]);

        $this->search('議事録', 5)->assertRedirect($url);

        $this->get($url)->assertOk()->assertSee('「議事録」に一致するメッセージはありません');
    }

    // TP-6-15。整数の範囲を超える値は、数字でない値と同じに扱われる
    #[DataProvider('invalidPages')]
    public function test_invalid_page_is_the_first_page(string $page): void
    {
        $this->messages(21);

        $xpath = $this->xpath($this->actingAs($this->me)->get('/search?q='.urlencode('議事録').'&'.$page)->assertOk());

        $this->assertCount(20, $this->bodies($xpath));
        $this->assertSame('議事録 21', $this->bodies($xpath)[0]);
        $this->assertSame(['前のページ'], $this->texts($xpath, self::PAGER.'/span'));
    }

    /** @return array<string, array{string}> */
    public static function invalidPages(): array
    {
        return [
            '空' => ['page='],
            '文字' => ['page=abc'],
            '数字で始まる文字' => ['page=2abc'],
            '小数' => ['page=1.5'],
            '0' => ['page=0'],
            '負の数' => ['page=-1'],
            '配列' => ['page[]=2'],
            '整数の範囲を超える' => ['page=99999999999999999999'],
        ];
    }

    // とても大きいページでも、エラーにせず、最後のページへ移す
    public function test_huge_page_moves_to_the_last_page(): void
    {
        $this->messages(21);

        $this->search('議事録', PHP_INT_MAX)->assertRedirect(route('search', ['q' => '議事録', 'page' => 2]));
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/search')->assertRedirect('/login');
        $this->get('/search?q=a')->assertRedirect('/login');
        $this->get('/search?q=')->assertRedirect('/login');
    }

    // 上部バーの検索欄（F-05）
    #[DataProvider('pagesWithTopbar')]
    public function test_topbar_form_sends_the_keyword_to_search(string $url): void
    {
        $xpath = $this->xpath($this->actingAs($this->me)->get($url)->assertOk());

        $form = $xpath->query('//div[@class="topbar__search"]/form')->item(0);
        $this->assertSame('GET', $form->getAttribute('method'));
        $this->assertSame(route('search'), $form->getAttribute('action'));
        $inputs = $xpath->query('.//input', $form);
        $this->assertSame(1, $inputs->length);
        $this->assertSame('q', $inputs->item(0)->getAttribute('name'));
        $this->assertSame('メッセージを検索', $inputs->item(0)->getAttribute('placeholder'));
        $this->assertSame('メッセージを検索', $inputs->item(0)->getAttribute('aria-label'));
        $this->assertFalse($inputs->item(0)->hasAttribute('value'));
    }

    /** @return array<string, array{string}> */
    public static function pagesWithTopbar(): array
    {
        return [
            'チャンネル一覧' => ['/channels'],
            '検索結果' => ['/search?q=a'],
        ];
    }

    // 一覧で出すチャンネルと投稿者は、まとめて取る
    public function test_query_count_does_not_grow_with_results(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->search('議事録')->assertOk();

            return count(DB::getQueryLog());
        };
        $this->message('議事録');
        $before = $count();

        Message::factory()->count(5)->create(['body' => '議事録']);

        // 増えるのは、サイドバーを含めて0
        $this->assertSame($before, $count());
    }

    // AC-6-1・AC-6-2・AC-6-4（初期データ）
    #[DataProvider('seededSearches')]
    public function test_seeded_search(string $email, array $channels): void
    {
        // setUp の「開発」と「佐藤 太郎」は、初期データと重なるので消す
        $this->channel->delete();
        $this->me->delete();
        $this->seed(DatabaseSeeder::class);

        $xpath = $this->xpath($this->search('リリース', user: User::where('email', $email)->firstOrFail())->assertOk());

        $this->assertSame('「リリース」の検索結果　'.count($channels).'件', $this->countLine($xpath));
        $this->assertSame($channels, array_map(
            fn (string $text) => ltrim($text, '# '),
            $this->metaTexts($xpath, self::ROW.'//span[@class="result__ch"]')
        ));
        $this->assertSame(array_fill(0, count($channels), 'リリース'), $this->texts($xpath, self::ROW.'//mark'));

        $general = Message::where('created_at', '2026-08-14 17:05:00')->sole();
        $this->assertSame(
            route('channels.show', $general->channel_id)."#message-{$general->id}",
            $this->links($xpath)[1]
        );
    }

    /** @return array<string, array{string, list<string>}> */
    public static function seededSearches(): array
    {
        return [
            '佐藤 太郎' => ['sato@example.com', ['開発', '全体連絡', '採用プロジェクト']],
            '高橋 健' => ['takahashi@example.com', ['開発', '全体連絡']],
        ];
    }
}
