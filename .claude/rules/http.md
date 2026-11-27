---
paths:
  - "app/Http/**"
  - "app/Policies/**"
  - "routes/*.php"
  - "app/Rules/**"
  - "app/Actions/**"
  - "lang/**"
---

# リクエストを受けるコードの決まり

判定の順番、断り方、URL、文言は、自分で決めない。中身と理由は設計書にある。設計書に無ければ聞く。

## 先に読む設計書

- 権限と断り方（404・403）、確かめる場所: `docs/design/permissions-api.md` の1章
- URL・ルート名・画面の状態の URL・フォームの項目名: 同 2章
- 公開API（`routes/api.php` を含む）: 同 3章
- 入力チェックの順と文言: `docs/design/screens.md` の4章
- 例外の扱いと、複数の表を書く処理: `docs/design/behavior.md` の3章

## しないこと

- URL・ルート名・クエリ・フォームの項目名を、自分で決めない。
- エラーや案内の文言を、自分で考えない。FormRequest・`app/Actions`・`app/Rules`・`lang` に書く文言も同じ。
- コントローラーで `authorize()` や `validate()` を呼ばない。
- 公開チャンネルかを見る前に、作成者かを確かめない（D-23）。
- メンバーを外す相手を、`scopeBindings()` で絞らない（D-35）。
- 返信への返信の拒否を、ポリシーに置かない（D-38）。

## 権限とルート

- 権限はルートの `can` で確かめる。入力チェック（FormRequest）より先に動くため。
- `{channel}` のルートは `can:view,channel` のグループに置く。ID の引数は `whereNumber`、`{channel}` の下の `{message}` は `scopeBindings()`。
- 受け取った `thread`・`edit` の ID は、`Channel` の `threadOrigin()`・`findMessage()` で探す。行へ戻る URL は `Message::url()` で作る。
- 保存には、入力チェックを通った値だけを渡す。投稿者・チャンネル・作成者は、サーバー側で関連を使って決める。

## 入力チェック

- FormRequest に書く（Fortify の新規登録だけは `CreateNewUser`）。各欄のルールは `bail` で始める。
- `min`・`max` を使う欄は、`string` を付けるか、文字列だと確かめた値を渡す。配列だと、要素の数を数える。
- 型を変える前に、文字列かを確かめる。
- 複数行の欄は、改行の CRLF をそろえてから文字数を数える。
- エラーで戻る先が送った画面と違うときだけ、`getRedirectUrl()` を書き換える。
